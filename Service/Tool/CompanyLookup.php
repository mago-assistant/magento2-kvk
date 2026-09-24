<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Kvk\Service\Tool;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MagoAssistant\Kvk\Service\OpenDataClient;
use MagoAssistant\Kvk\Service\SbiCatalog;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Answers "which industry is this customer in?" from the KVK open dataset: SBI activities, legal
 * form and status of a Dutch BV or NV.
 *
 * It sits next to vies_vat_check (mago-assistant/magento2-vies) rather than on top of it. VIES
 * answers with a name and an address but never a KVK number, a Dutch VAT number cannot be turned
 * into one, and the open dataset accepts nothing else. So the KVK number comes from the admin, or
 * from the attribute the shop stores it in (mago/kvk/kvk_attribute).
 */
class CompanyLookup implements ToolInterface
{
    private const XML_PATH_ATTRIBUTE = 'mago/kvk/kvk_attribute';
    private const MAIN_ACTIVITY = 'Hoofdactiviteit';
    private const ACTIVE = 'J';

    private const INSOLVENCY = [
        'FAIL' => 'faillissement (bankruptcy)',
        'SURS' => 'surseance van betaling (suspension of payments)',
        'SSAN' => 'schuldsanering (debt restructuring)',
    ];

    public function __construct(
        private readonly OpenDataClient $client,
        private readonly SbiCatalog $sbiCatalog,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'kvk_company_lookup';
    }

    public function getDescription(): string
    {
        return 'Look up the industry (SBI activities), legal form and status (active, insolvency) of a '
            . 'Dutch BV or NV in the KVK open dataset, by its 8-digit KVK number, or by an '
            . 'order_number when the shop stores the KVK number on its orders or customers. Does not '
            . 'return a company name or address and cannot search by name or VAT number: for those, '
            . 'use vies_vat_check.';
    }

    /**
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kvk_number' => [
                    'type' => 'string',
                    'description' => '8-digit KVK (Chamber of Commerce) number, e.g. "17085815". '
                        . 'Leave out when passing an order_number instead.',
                ],
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Order increment id, e.g. "000000563". The KVK number is read '
                        . 'from that order. Leave out when passing a kvk_number.',
                ],
            ],
        ];
    }

    /**
     * A bare KVK number touches no shop data, so the assistant's own skill permission covers it.
     * An order does, and an empty input has to resolve to the strictest resource (fail closed).
     *
     * @param array<string,mixed> $input
     */
    public function getMagentoAcl(array $input = []): string
    {
        $kvkNumber = trim((string)($input['kvk_number'] ?? ''));
        $orderNumber = trim((string)($input['order_number'] ?? ''));

        return $kvkNumber !== '' && $orderNumber === '' ? '' : 'Magento_Sales::actions_view';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function isReadOnlyAction(array $input): bool
    {
        return true;
    }

    /**
     * Nested records re-match by key name, so the activity fields are listed here too. Everything is
     * public: the open dataset only covers BVs and NVs and carries no name, address or person.
     */
    public function getFieldClassification(string $action = ''): array
    {
        return [
            'found' => [PiiClass::PUBLIC],
            'reason' => [PiiClass::PUBLIC],
            'kvk_number' => [PiiClass::PUBLIC],
            'order_number' => [PiiClass::PUBLIC],
            'active' => [PiiClass::PUBLIC],
            'legal_form' => [PiiClass::PUBLIC],
            'insolvency' => [PiiClass::PUBLIC],
            'start_date' => [PiiClass::PUBLIC],
            'postcode_region' => [PiiClass::PUBLIC],
            'main_activity' => [PiiClass::PUBLIC],
            'other_activities' => [PiiClass::PUBLIC],
            'sbi_code' => [PiiClass::PUBLIC],
            'description' => [PiiClass::PUBLIC],
            'description_en' => [PiiClass::PUBLIC],
            'sector' => [PiiClass::PUBLIC],
            'sbi_version' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Name the main activity\'s description as the industry, in the admin\'s language, with '
            . 'the SBI code in brackets. found=false does not mean the company does not exist: the '
            . 'open dataset only covers BVs and NVs, so a sole trader (eenmanszaak), VOF or '
            . 'foundation is never in it. postcode_region is only the first two digits of the '
            . 'postcode.';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $orderNumber = trim((string)($params['order_number'] ?? ''));
        $kvkNumber = trim((string)($params['kvk_number'] ?? ''));

        $fromOrder = $kvkNumber === '' && $orderNumber !== '';
        if ($fromOrder) {
            $kvkNumber = $this->kvkNumberOnOrder($orderNumber);
            if ($kvkNumber === null) {
                return ['error' => 'Order ' . $orderNumber . ' was not found'];
            }
            if ($kvkNumber === '') {
                return ['error' => 'Order ' . $orderNumber . ' has no KVK number stored; ask the admin for it'];
            }
        }

        $kvkNumber = preg_replace('/\D/', '', $kvkNumber) ?? '';
        if (strlen($kvkNumber) !== 8) {
            return ['error' => 'kvk_company_lookup needs an 8-digit kvk_number or an order_number'];
        }

        $record = $this->client->fetch($kvkNumber);
        if (isset($record['error'])) {
            return $record;
        }

        $base = [
            'kvk_number' => $kvkNumber,
            'order_number' => $fromOrder ? $orderNumber : null,
        ];

        if (isset($record['not_found'])) {
            return $base + ['found' => false, 'reason' => (string)$record['not_found']];
        }

        return $base + $this->describe($record);
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function describe(array $record): array
    {
        $main = null;
        $others = [];
        foreach ((array)($record['activiteiten'] ?? []) as $activity) {
            if (!is_array($activity) || !isset($activity['sbiCode'])) {
                continue;
            }
            $code = (string)$activity['sbiCode'];
            $described = $this->sbiCatalog->describe($code)
                ?? ['sbi_code' => $code, 'description' => '', 'description_en' => '', 'sector' => ''];

            if ($main === null && ($activity['soortActiviteit'] ?? '') === self::MAIN_ACTIVITY) {
                $main = $described;
            } else {
                $others[] = $described;
            }
        }

        $insolvency = (string)($record['insolventieCode'] ?? '');

        return [
            'found' => true,
            'active' => ($record['actief'] ?? '') === self::ACTIVE,
            'legal_form' => (string)($record['rechtsvormCode'] ?? ''),
            'insolvency' => $insolvency !== '' ? (self::INSOLVENCY[$insolvency] ?? $insolvency) : null,
            'start_date' => $this->date((string)($record['datumAanvang'] ?? '')),
            'postcode_region' => isset($record['postcodeRegio']) ? (string)$record['postcodeRegio'] : null,
            'main_activity' => $main,
            'other_activities' => $others,
            'sbi_version' => 'SBI 2025',
        ];
    }

    /**
     * Null when the order does not exist, '' when it holds no KVK number.
     */
    private function kvkNumberOnOrder(string $incrementId): ?string
    {
        $attribute = trim((string)$this->scopeConfig->getValue(self::XML_PATH_ATTRIBUTE));
        if ($attribute === '') {
            return '';
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            return $this->kvkNumberFrom($order, $attribute);
        }

        return null;
    }

    private function kvkNumberFrom(OrderInterface $order, string $attribute): string
    {
        $billing = $order->getBillingAddress();
        $value = $billing instanceof DataObject ? trim((string)$billing->getData($attribute)) : '';
        if ($value !== '' || !$order->getCustomerId()) {
            return $value;
        }

        try {
            $custom = $this->customerRepository->getById((int)$order->getCustomerId())
                ->getCustomAttribute($attribute);
        } catch (LocalizedException) {
            return '';
        }

        return $custom !== null ? trim((string)$custom->getValue()) : '';
    }

    private function date(string $yyyymmdd): ?string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $yyyymmdd, $m) ? "$m[1]-$m[2]-$m[3]" : null;
    }
}
