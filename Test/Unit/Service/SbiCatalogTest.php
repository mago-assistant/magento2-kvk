<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Kvk\Test\Unit\Service;

use MagoAssistant\Kvk\Service\SbiCatalog;
use PHPUnit\Framework\TestCase;

class SbiCatalogTest extends TestCase
{
    public function testDescribesAFullCodeWithItsSector(): void
    {
        $described = (new SbiCatalog())->describe('64210');

        $this->assertSame('64210', $described['sbi_code'] ?? null);
        $this->assertSame('Activities of holding companies', $described['description_en'] ?? null);
        $this->assertStringStartsWith('Activiteiten op het gebied van financiële', $described['sector'] ?? '');
    }

    public function testFallsBackToTheNearestParent(): void
    {
        $described = (new SbiCatalog())->describe('64219');

        $this->assertSame('64219', $described['sbi_code'] ?? null);
        $this->assertSame('Activities of holding companies', $described['description_en'] ?? null);
    }

    public function testUnknownCodeIsNull(): void
    {
        $this->assertNull((new SbiCatalog())->describe('9'));
    }
}
