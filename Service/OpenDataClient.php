<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Kvk\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The KVK "Open Dataset Basis Bedrijfsgegevens": free and keyless, but it only answers for BVs and
 * NVs and allows one request per minute per IP address. Every answer is cached, and so is a rate
 * limit hit, so a chat that asks twice does not burn the minute twice.
 */
class OpenDataClient
{
    public const CACHE_TAG = 'MAGO_KVK';

    private const ENDPOINT = 'https://opendata.kvk.nl/api/v1/hvds/basisbedrijfsgegevens/kvknummer/';
    private const CACHE_PREFIX = 'mago_kvk_';
    private const MISS_LIFETIME = 3600;
    private const RATE_LIMIT_LIFETIME = 60;
    private const XML_PATH_CACHE_DAYS = 'mago/kvk/cache_days';
    private const RATE_LIMITED = 'The KVK open dataset allows one request per minute; try again shortly';

    /**
     * Answered with a 404 like a real miss, but it means the record is being processed: caching it
     * as a miss would hide a company that exists for the whole miss lifetime.
     */
    private const TEMPORARY_FAULT = 'IPD1002';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly CacheInterface $cache,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * One of three shapes: the register's record as it answered, ['not_found' => reason] when the
     * register holds nothing it may share for this number, or ['error' => reason].
     *
     * @param string $kvkNumber Eight digits, already normalised
     * @return array<string,mixed>
     */
    public function fetch(string $kvkNumber): array
    {
        if (!preg_match('/^\d{8}$/', $kvkNumber)) {
            return ['error' => 'A KVK number has 8 digits'];
        }

        $cached = $this->cache->load(self::CACHE_PREFIX . $kvkNumber);
        if (is_string($cached) && $cached !== '') {
            $decoded = $this->json->unserialize($cached);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ($this->cache->load(self::CACHE_PREFIX . 'rate_limited')) {
            return ['error' => self::RATE_LIMITED];
        }

        $result = $this->request($kvkNumber);
        $this->remember($kvkNumber, $result);

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function request(string $kvkNumber): array
    {
        // A fresh client per call: the framework Curl is shared, and headers set on it would ride
        // along on every other skill's requests in the same PHP process.
        $curl = $this->curlFactory->create();
        try {
            $curl->setTimeout(15);
            $curl->addHeader('Accept', 'application/json');
            $curl->get(self::ENDPOINT . $kvkNumber);
            $status = $curl->getStatus();
            $body = $curl->getBody();
        } catch (\Throwable $e) {
            return ['error' => 'Could not reach the KVK open dataset: ' . $e->getMessage()];
        }

        // Decoded outside the network try: a gateway can answer a 429 or a 5xx with an HTML page,
        // and that must still reach the status branches below, not read as unreachable.
        try {
            $decoded = $this->json->unserialize($body !== '' ? $body : '{}');
        } catch (\InvalidArgumentException) {
            $decoded = null;
        }

        if ($status === 429) {
            $this->cache->save('1', self::CACHE_PREFIX . 'rate_limited', [self::CACHE_TAG], self::RATE_LIMIT_LIFETIME);

            return ['error' => self::RATE_LIMITED];
        }

        if ($status === 200 && is_array($decoded) && !isset($decoded['fout'])) {
            return $decoded;
        }

        $reason = $this->reason($decoded) ?: 'HTTP ' . $status;

        if ($status === 404 && !str_contains($reason, self::TEMPORARY_FAULT)) {
            return ['not_found' => $reason];
        }

        return ['error' => 'The KVK open dataset answered: ' . $reason];
    }

    /**
     * @param array<string,mixed> $result
     */
    private function remember(string $kvkNumber, array $result): void
    {
        if (isset($result['error'])) {
            return;
        }

        $lifetime = isset($result['not_found'])
            ? self::MISS_LIFETIME
            : max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_CACHE_DAYS)) * 86400;

        $this->cache->save(
            (string)$this->json->serialize($result),
            self::CACHE_PREFIX . $kvkNumber,
            [self::CACHE_TAG],
            $lifetime
        );
    }

    private function reason(mixed $decoded): string
    {
        if (!is_array($decoded) || !is_array($decoded['fout'] ?? null)) {
            return '';
        }

        $messages = [];
        foreach ($decoded['fout'] as $fault) {
            if (is_array($fault)) {
                $messages[] = trim(($fault['code'] ?? '') . ' ' . ($fault['omschrijving'] ?? ''));
            }
        }

        return implode('; ', array_filter($messages));
    }
}
