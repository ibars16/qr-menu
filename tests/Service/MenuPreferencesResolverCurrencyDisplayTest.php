<?php

namespace App\Tests\Service;

use App\Service\MenuPreferencesResolver;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for MenuPreferencesResolver::getCurrencyDisplay() — the
 * symbol-when-unambiguous, code-otherwise convention the stacked multi-price
 * rendering (menu/_price.html.twig) relies on. No DB/kernel needed: this
 * class only reads the static config/currencies.php array.
 */
final class MenuPreferencesResolverCurrencyDisplayTest extends TestCase
{
    private MenuPreferencesResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MenuPreferencesResolver(dirname(__DIR__, 2));
    }

    public function testUnambiguousSymbolIsUsed(): void
    {
        self::assertSame('€', $this->resolver->getCurrencyDisplay('EUR'));
        self::assertSame('£', $this->resolver->getCurrencyDisplay('GBP'));
        self::assertSame('₹', $this->resolver->getCurrencyDisplay('INR'));
    }

    public function testAmbiguousSymbolFallsBackToTheCode(): void
    {
        // config/currencies.php gives "$" to NZD/AUD/USD/CAD/SGD/HKD/MXN —
        // none of them may show a bare "$".
        self::assertSame('USD', $this->resolver->getCurrencyDisplay('USD'));
        self::assertSame('AUD', $this->resolver->getCurrencyDisplay('AUD'));
        self::assertSame('MXN', $this->resolver->getCurrencyDisplay('MXN'));

        // "kr" is shared by SEK/NOK/DKK.
        self::assertSame('SEK', $this->resolver->getCurrencyDisplay('SEK'));
        self::assertSame('NOK', $this->resolver->getCurrencyDisplay('NOK'));
    }

    public function testUnknownCurrencyFallsBackToTheCodeItself(): void
    {
        self::assertSame('XYZ', $this->resolver->getCurrencyDisplay('XYZ'));
    }
}
