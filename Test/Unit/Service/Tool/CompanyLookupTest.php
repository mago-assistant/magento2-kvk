<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Kvk\Test\Unit\Service\Tool;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use MagoAssistant\Kvk\Service\OpenDataClient;
use MagoAssistant\Kvk\Service\SbiCatalog;
use MagoAssistant\Kvk\Service\Tool\CompanyLookup;
use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CompanyLookupTest extends TestCase
{
    private const RECORD = [
        'datumAanvang' => '19941003',
        'actief' => 'J',
        'rechtsvormCode' => 'NV',
        'postcodeRegio' => 55,
        'insolventieCode' => 'SURS',
        'activiteiten' => [
            ['sbiCode' => '47110', 'soortActiviteit' => 'Nevenactiviteit'],
            ['sbiCode' => '64210', 'soortActiviteit' => 'Hoofdactiviteit'],
        ],
        'lidstaat' => 'NL',
    ];

    private OpenDataClient&MockObject $client;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private AuthorizationInterface&MockObject $authorization;
    private CompanyLookup $tool;

    protected function setUp(): void
    {
        $this->client = $this->createMock(OpenDataClient::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnSelf();
        $criteriaBuilder->method('setPageSize')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->tool = new CompanyLookup(
            $this->client,
            new SbiCatalog(),
            $this->orderRepository,
            $this->customerRepository,
            $criteriaBuilder,
            $this->scopeConfig,
            $this->authorization
        );
    }

    public function testMapsTheRecord(): void
    {
        $this->client->expects($this->once())->method('fetch')->with('17085815')->willReturn(self::RECORD);

        $result = $this->tool->execute(['kvk_number' => '1708 5815']);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['active']);
        $this->assertSame('NV', $result['legal_form']);
        $this->assertSame('1994-10-03', $result['start_date']);
        $this->assertSame('55', $result['postcode_region']);
        $this->assertStringStartsWith('surseance', $result['insolvency']);
        $this->assertSame('64210', $result['main_activity']['sbi_code']);
        $this->assertSame('47110', $result['other_activities'][0]['sbi_code']);
    }

    public function testMapsAnInactiveCompanyWithoutInsolvency(): void
    {
        $record = self::RECORD;
        $record['actief'] = 'N';
        unset($record['insolventieCode']);
        $this->client->method('fetch')->willReturn($record);

        $result = $this->tool->execute(['kvk_number' => '17085815']);

        $this->assertFalse($result['active']);
        $this->assertNull($result['insolvency']);
    }

    public function testMalformedStartDateIsNull(): void
    {
        $record = self::RECORD;
        $record['datumAanvang'] = '1994-10';
        $this->client->method('fetch')->willReturn($record);

        $this->assertNull($this->tool->execute(['kvk_number' => '17085815'])['start_date']);
    }

    public function testKvkAndOrderNumbersAreTokenised(): void
    {
        $classes = $this->tool->getFieldClassification();

        $this->assertSame([PiiClass::TOKENISE, 'kvk'], $classes['kvk_number']);
        $this->assertSame([PiiClass::TOKENISE, 'order'], $classes['order_number']);
    }

    public function testClassificationCoversEveryKeyTheToolReturns(): void
    {
        $this->client->method('fetch')->willReturn(self::RECORD);
        $classes = $this->tool->getFieldClassification();

        $result = $this->tool->execute(['kvk_number' => '17085815']);
        $keys = [];
        $collect = static function (array $node) use (&$collect, &$keys): void {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
                if (is_array($value)) {
                    $collect($value);
                }
            }
        };
        $collect($result);

        $this->assertSame([], array_values(array_diff(array_unique($keys), array_keys($classes))));
    }

    public function testMissCarriesTheReason(): void
    {
        $this->client->method('fetch')->willReturn(['not_found' => 'IPD0005 niet leverbaar']);

        $this->assertSame(
            ['kvk_number' => '12345678', 'order_number' => null, 'found' => false, 'reason' => 'IPD0005 niet leverbaar'],
            $this->tool->execute(['kvk_number' => '12345678'])
        );
    }

    public function testInvalidNumberMakesNoRequest(): void
    {
        $this->client->expects($this->never())->method('fetch');

        $this->assertArrayHasKey('error', $this->tool->execute(['kvk_number' => '123']));
    }

    public function testOrderWithoutConfiguredAttributeIsAnError(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');
        $this->orderRepository->expects($this->never())->method('getList');

        $this->assertStringContainsString('no KVK number', $this->tool->execute(['order_number' => '1'])['error']);
    }

    public function testOrderFallsBackToTheCustomerAttribute(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->authorization->method('isAllowed')->with('Magento_Customer::manage')->willReturn(true);
        $this->givenOrder(billingValue: '', customerValue: '17085815');
        $this->client->expects($this->once())->method('fetch')->with('17085815')->willReturn(self::RECORD);

        $this->assertSame('000000001', $this->tool->execute(['order_number' => '000000001'])['order_number']);
    }

    public function testBillingAddressWinsOverTheCustomer(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->givenOrder(billingValue: '11111111', customerValue: '17085815');
        $this->authorization->expects($this->never())->method('isAllowed');
        $this->customerRepository->expects($this->never())->method('getById');
        $this->client->expects($this->once())->method('fetch')->with('11111111')->willReturn(self::RECORD);

        $this->tool->execute(['order_number' => '000000001']);
    }

    public function testMissingOrderIsNotReportedAsMissingNumber(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $results = $this->createMock(OrderSearchResultInterface::class);
        $results->method('getItems')->willReturn([]);
        $this->orderRepository->method('getList')->willReturn($results);

        $this->assertSame('Order 404 was not found', $this->tool->execute(['order_number' => '404'])['error']);
    }

    public function testCustomerFallbackNeedsCustomerPermission(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->givenOrder(billingValue: '', customerValue: '17085815');
        $this->authorization->method('isAllowed')->with('Magento_Customer::manage')->willReturn(false);
        $this->customerRepository->expects($this->never())->method('getById');
        $this->client->expects($this->never())->method('fetch');

        $error = $this->tool->execute(['order_number' => '1'])['error'];

        $this->assertStringContainsString('needs customer permission', $error);
    }

    public function testCustomerWithoutTheAttributeIsAMissingNumber(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->givenOrder(billingValue: '', customerValue: null);
        $this->client->expects($this->never())->method('fetch');

        $this->assertStringContainsString('no KVK number', $this->tool->execute(['order_number' => '1'])['error']);
    }

    public function testGuestOrderWithoutBillingNumberNeverReadsACustomer(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->givenOrder(billingValue: '', customerValue: '17085815', customerId: null);
        $this->authorization->expects($this->never())->method('isAllowed');
        $this->customerRepository->expects($this->never())->method('getById');
        $this->client->expects($this->never())->method('fetch');

        $this->assertStringContainsString('no KVK number', $this->tool->execute(['order_number' => '1'])['error']);
    }

    public function testDeletedCustomerIsNotReportedAsMissingNumber(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->givenOrder(billingValue: '', customerValue: null, stubCustomer: false);
        $this->customerRepository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->client->expects($this->never())->method('fetch');

        $error = $this->tool->execute(['order_number' => '1'])['error'];

        $this->assertStringContainsString('customer account no longer exists', $error);
    }

    public function testFailingCustomerReadIsNotHidden(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->givenOrder(billingValue: '', customerValue: null, stubCustomer: false);
        $this->customerRepository->method('getById')->willThrowException(new LocalizedException(__('db down')));

        $this->expectException(LocalizedException::class);
        $this->tool->execute(['order_number' => '1']);
    }

    public function testExplicitNumberDoesNotClaimToComeFromTheOrder(): void
    {
        $this->client->method('fetch')->willReturn(self::RECORD);
        $this->orderRepository->expects($this->never())->method('getList');

        $this->assertNull($this->tool->execute(['kvk_number' => '17085815', 'order_number' => '1'])['order_number']);
    }

    public function testRecordWithoutMainActivityListsTheRestAsOthers(): void
    {
        $record = self::RECORD;
        $record['activiteiten'] = [['sbiCode' => '47110', 'soortActiviteit' => 'Nevenactiviteit']];
        $this->client->method('fetch')->willReturn($record);

        $result = $this->tool->execute(['kvk_number' => '17085815']);

        $this->assertNull($result['main_activity']);
        $this->assertCount(1, $result['other_activities']);
    }

    public function testClientErrorPassesThrough(): void
    {
        $this->client->method('fetch')->willReturn(['error' => 'rate limited']);

        $this->assertSame(['error' => 'rate limited'], $this->tool->execute(['kvk_number' => '17085815']));
    }

    public function testNonScalarInputIsTreatedAsMissing(): void
    {
        $this->client->expects($this->never())->method('fetch');

        $this->assertSame('Magento_Sales::actions_view', $this->tool->getMagentoAcl(['kvk_number' => ['17085815']]));
        $this->assertArrayHasKey('error', $this->tool->execute(['kvk_number' => ['17085815']]));
    }

    public function testBareKvkNumberIsGrantedPerUser(): void
    {
        $this->assertSame(Acl::MAGO_PER_USER, $this->tool->getMagentoAcl(['kvk_number' => '17085815']));
        $this->assertSame('Magento_Sales::actions_view', $this->tool->getMagentoAcl([]));
        $this->assertSame('Magento_Sales::actions_view', $this->tool->getMagentoAcl(['order_number' => '1']));
        $this->assertSame(
            'Magento_Sales::actions_view',
            $this->tool->getMagentoAcl(['kvk_number' => '17085815', 'order_number' => '1'])
        );
    }

    private function givenOrder(string $billingValue, ?string $customerValue, ?int $customerId = 7, bool $stubCustomer = true): void
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->with('kvk_number')->willReturn($billingValue);
        $order = $this->createMock(Order::class);
        $order->method('getBillingAddress')->willReturn($address);
        $order->method('getCustomerId')->willReturn($customerId);
        $results = $this->createMock(OrderSearchResultInterface::class);
        $results->method('getItems')->willReturn([$order]);
        $this->orderRepository->method('getList')->willReturn($results);

        $attribute = null;
        if ($customerValue !== null) {
            $attribute = $this->createMock(AttributeInterface::class);
            $attribute->method('getValue')->willReturn($customerValue);
        }
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getCustomAttribute')->with('kvk_number')->willReturn($attribute);
        if ($customerId !== null && $stubCustomer) {
            $this->customerRepository->method('getById')->with($customerId)->willReturn($customer);
        }
    }
}
