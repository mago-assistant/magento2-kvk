<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Kvk\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Kvk\Service\OpenDataClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OpenDataClientTest extends TestCase
{
    private Curl&MockObject $curl;
    private CacheInterface&MockObject $cache;
    private OpenDataClient $client;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('7');

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->client = new OpenDataClient($curlFactory, new Json(), $this->cache, $scopeConfig);
    }

    public function testCacheHitMakesNoRequest(): void
    {
        $this->cache->method('load')->willReturn('{"rechtsvormCode":"BV"}');
        $this->curl->expects($this->never())->method('get');

        $this->assertSame(['rechtsvormCode' => 'BV'], $this->client->fetch('12345678'));
    }

    public function testRecordIsCachedForTheConfiguredDays(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond(200, '{"actief":"J","rechtsvormCode":"BV"}');
        $this->cache->expects($this->once())->method('save')
            ->with($this->anything(), 'mago_kvk_12345678', [OpenDataClient::CACHE_TAG], 7 * 86400);

        $this->assertSame(['actief' => 'J', 'rechtsvormCode' => 'BV'], $this->client->fetch('12345678'));
    }

    public function testMissIsCachedBriefly(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond(404, '{"fout":[{"code":"IPD0005","omschrijving":"kan niet worden geleverd"}]}');
        $this->cache->expects($this->once())->method('save')
            ->with($this->anything(), 'mago_kvk_12345678', [OpenDataClient::CACHE_TAG], 3600);

        $this->assertSame(['not_found' => 'IPD0005 kan niet worden geleverd'], $this->client->fetch('12345678'));
    }

    public function testTemporaryFaultIsAnUncachedError(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond(404, '{"fout":[{"code":"IPD1002","omschrijving":"in behandeling"}]}');
        $this->cache->expects($this->never())->method('save');

        $this->assertArrayHasKey('error', $this->client->fetch('12345678'));
    }

    public function testRateLimitIsRememberedForAMinute(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond(429, '');
        $this->cache->expects($this->once())->method('save')
            ->with('1', 'mago_kvk_rate_limited', [OpenDataClient::CACHE_TAG], 60);

        $this->assertArrayHasKey('error', $this->client->fetch('12345678'));
    }

    public function testRateLimitWithANonJsonBodyIsStillRemembered(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond(429, '<html>Too Many Requests</html>');
        $this->cache->expects($this->once())->method('save')
            ->with('1', 'mago_kvk_rate_limited', [OpenDataClient::CACHE_TAG], 60);

        $this->assertStringContainsString('one request per minute', $this->client->fetch('12345678')['error'] ?? '');
    }

    public function testFaultInASuccessfulResponseIsAnUncachedError(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->respond(200, '{"fout":[{"code":"IPD9999","omschrijving":"storing"}]}');
        $this->cache->expects($this->never())->method('save');

        $this->assertStringContainsString('IPD9999 storing', $this->client->fetch('12345678')['error'] ?? '');
    }

    public function testUnnormalisedNumberNeverReachesTheUrl(): void
    {
        $this->curl->expects($this->never())->method('get');

        $this->assertArrayHasKey('error', $this->client->fetch('1234/../5678'));
    }

    public function testActiveRateLimitSkipsTheRequest(): void
    {
        $this->cache->method('load')->willReturnMap([
            ['mago_kvk_12345678', false],
            ['mago_kvk_rate_limited', '1'],
        ]);
        $this->curl->expects($this->never())->method('get');

        $this->assertArrayHasKey('error', $this->client->fetch('12345678'));
    }

    public function testTransportFailureIsAnError(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->curl->method('get')->willThrowException(new \RuntimeException('timeout'));

        $this->assertStringContainsString('timeout', $this->client->fetch('12345678')['error'] ?? '');
    }

    private function respond(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }
}
