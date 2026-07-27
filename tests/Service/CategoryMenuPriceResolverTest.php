<?php

namespace App\Tests\Service;

use App\Service\CategoryMenuPriceResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for CategoryMenuPriceResolver::resolve() — the
 * validate-and-normalize step behind the Menús screen's create/edit forms
 * (see Admin\MenusController). No DB/kernel needed: it only ever reads a
 * plain decoded-JSON array.
 */
final class CategoryMenuPriceResolverTest extends TestCase
{
    private CategoryMenuPriceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CategoryMenuPriceResolver();
    }

    public function testConvertsPriceToCents(): void
    {
        $result = $this->resolver->resolve(['menuPrice' => '15.90']);

        self::assertTrue($result['ok']);
        self::assertSame(1590, $result['menuPrice']);
    }

    public function testRejectsMissingPrice(): void
    {
        self::assertFalse($this->resolver->resolve([])['ok']);
    }

    public function testRejectsZeroOrNegativePrice(): void
    {
        self::assertFalse($this->resolver->resolve(['menuPrice' => '0'])['ok']);
        self::assertFalse($this->resolver->resolve(['menuPrice' => '-5'])['ok']);
    }

    public function testRejectsNonNumericPrice(): void
    {
        self::assertFalse($this->resolver->resolve(['menuPrice' => 'abc'])['ok']);
    }

    public function testDescriptionIsTrimmedAndBlankBecomesNull(): void
    {
        $result = $this->resolver->resolve(['menuPrice' => '12', 'menuDescription' => '  Incluye pan y bebida  ']);
        self::assertSame('Incluye pan y bebida', $result['menuDescription']);

        $blank = $this->resolver->resolve(['menuPrice' => '12', 'menuDescription' => '   ']);
        self::assertNull($blank['menuDescription']);
    }

    public function testDescriptionIsOptional(): void
    {
        $result = $this->resolver->resolve(['menuPrice' => '12']);

        self::assertTrue($result['ok']);
        self::assertNull($result['menuDescription']);
    }
}
