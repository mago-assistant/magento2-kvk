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
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use MagoAssistant\Kvk\Service\OpenDataClient;
use MagoAssistant\Kvk\Service\SbiCatalog;
use MagoAssistant\Kvk\Service\Tool\CompanyLookup;
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
    private CompanyLookup $tool;

    protected function setUp(): void
    {
        $this->client = $this->createMock(OpenDataClient::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
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
            $this->scopeConfig
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
        $this->givenOrder(billingValue: '', customerValue: '17085815');
        $this->client->expects($this->once())->method('fetch')->with('17085815')->willReturn(self::RECORD);

        $this->assertSame('000000001', $this->tool->execute(['order_number' => '000000001'])['order_number']);
    }

    public function testBillingAddressWinsOverTheCustomer(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->givenOrder(billingValue: '11111111', customerValue: '17085815');
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

    public function testCustomerWithoutTheAttributeIsAMissingNumber(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('kvk_number');
        $this->givenOrder(billingValue: '', customerValue: null);
        $this->client->expects($this->never())->method('fetch');

        $this->assertStringContainsString('no KVK number', $this->tool->execute(['order_number' => '1'])['error']);
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

    public function testAclIsOnlyWaivedForABareKvkNumber(): void
    {
        $this->assertSame('', $this->tool->getMagentoAcl(['kvk_number' => '17085815']));
        $this->assertSame('Magento_Sales::actions_view', $this->tool->getMagentoAcl([]));
        $this->assertSame('Magento_Sales::actions_view', $this->tool->getMagentoAcl(['order_number' => '1']));
        $this->assertSame(
            'Magento_Sales::actions_view',
            $this->tool->getMagentoAcl(['kvk_number' => '17085815', 'order_number' => '1'])
        );
    }

    private function givenOrder(string $billingValue, ?string $customerValue): void
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->with('kvk_number')->willReturn($billingValue);
        $order = $this->createMock(Order::class);
        $order->method('getBillingAddress')->willReturn($address);
        $order->method('getCustomerId')->willReturn(7);
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
        $this->customerRepository->method('getById')->with(7)->willReturn($customer);
    }
}
