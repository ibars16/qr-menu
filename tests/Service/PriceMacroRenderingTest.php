<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the actual menu/_price.html.twig macro (shared by all 3 public
 * layouts) against a standalone Twig environment — no kernel/DB needed,
 * since the macro only touches plain entity getters. This is the real
 * rendering rule, not a reimplementation of it.
 *
 * For the multi-price (stacked) case, assertions target the semantic
 * structure — one ".price-row" per price, in order, each with its own
 * ".price-item-label" (or none, for an unlabeled base price) and
 * ".price-item-value" carrying its own currency — rather than the raw HTML
 * string, since that structure is exactly what the per-layout CSS grid
 * (label left/muted, price right) depends on.
 */
final class PriceMacroRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $this->twig = new Environment(new FilesystemLoader($projectDir . '/templates'));
    }

    private function render(Product $product, string $currency, bool $showCurrency, string $currencyDisplay = ''): string
    {
        $template = $this->twig->createTemplate(
            "{% import 'menu/_price.html.twig' as priceMacro %}{{ priceMacro.price(product, currency, showCurrency, currencyDisplay) }}"
        );

        return trim($template->render([
            'product' => $product,
            'currency' => $currency,
            'showCurrency' => $showCurrency,
            'currencyDisplay' => $currencyDisplay,
        ]));
    }

    /** @return array<int, array{label: ?string, value: ?string}> one entry per ".price-row", in document order */
    private function parseRows(string $html): array
    {
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');
        $xpath = new \DOMXPath($dom);

        $rows = [];
        foreach ($xpath->query('//*[@class="price-row"]') as $rowNode) {
            $labelNodes = $xpath->query('.//*[@class="price-item-label"]', $rowNode);
            $valueNodes = $xpath->query('.//*[@class="price-item-value"]', $rowNode);
            $rows[] = [
                'label' => $labelNodes->length > 0 ? trim($labelNodes->item(0)->textContent) : null,
                'value' => $valueNodes->length > 0 ? trim($valueNodes->item(0)->textContent) : null,
            ];
        }

        return $rows;
    }

    private function product(int $basePrice = 1000): Product
    {
        $product = new Product();
        $product->setBasePrice($basePrice);
        $product->setConvertedPrice($basePrice);

        return $product;
    }

    public function testSimpleProductRendersOnlyItsSinglePlainPrice(): void
    {
        $product = $this->product(1000);

        // Plain text, no markup at all, using $currencyDisplay — same
        // symbol-or-code convention as the price-variant path below, so a
        // menu mixing simple and variant-priced dishes shows one consistent
        // currency label throughout (see _price.html.twig's own docblock).
        self::assertSame('10.00 €', $this->render($product, 'EUR', true, '€'));
    }

    public function testSimpleProductWithoutCurrencySuffix(): void
    {
        $product = $this->product(1000);

        self::assertSame('10.00', $this->render($product, 'EUR', false, '€'));
    }

    public function testVariantsRenderOneRowPerPriceBaseFirstThenEachVariantInOrder(): void
    {
        $product = $this->product(800); // "Ración" — the base price
        $product->setBasePriceLabel('Ración');
        $product->setConvertedPrice(800);

        $tapa = new ProductPriceVariant();
        $tapa->setLabel('Tapa');
        $tapa->setPrice(300);
        $tapa->setPosition(0);
        $product->addPriceVariant($tapa);

        $media = new ProductPriceVariant();
        $media->setLabel('Media ración');
        $media->setPrice(500);
        $media->setPosition(1);
        $product->addPriceVariant($media);

        $rows = $this->parseRows($this->render($product, 'EUR', true, '€'));

        self::assertCount(3, $rows, 'one .price-row per price: base + 2 variants');
        self::assertSame(['label' => 'Ración', 'value' => '8.00 €'], $rows[0]);
        self::assertSame(['label' => 'Tapa', 'value' => '3.00 €'], $rows[1]);
        self::assertSame(['label' => 'Media ración', 'value' => '5.00 €'], $rows[2]);
    }

    public function testEveryRowCarriesItsOwnCurrencyNotJustOne(): void
    {
        $product = $this->product(800);
        $product->setBasePriceLabel('Ración');
        $product->setConvertedPrice(800);

        $tapa = new ProductPriceVariant();
        $tapa->setLabel('Tapa');
        $tapa->setPrice(300);
        $product->addPriceVariant($tapa);

        $media = new ProductPriceVariant();
        $media->setLabel('Media ración');
        $media->setPrice(500);
        $product->addPriceVariant($media);

        $rows = $this->parseRows($this->render($product, 'EUR', true, '€'));

        foreach ($rows as $row) {
            self::assertStringEndsWith('€', $row['value'], 'every stacked row must carry its own currency');
        }
    }

    public function testCurrencyOmittedFromEveryRowWhenShowCurrencyIsFalse(): void
    {
        $product = $this->product(800);
        $product->setBasePriceLabel('Ración');
        $tapa = new ProductPriceVariant();
        $tapa->setLabel('Tapa');
        $tapa->setPrice(300);
        $product->addPriceVariant($tapa);

        $rows = $this->parseRows($this->render($product, 'EUR', false, '€'));

        self::assertSame('8.00', $rows[0]['value']);
        self::assertSame('3.00', $rows[1]['value']);
    }

    public function testUsesTheSymbolOrCodeConventionPassedInNotTheRawCurrencyCode(): void
    {
        // $currencyDisplay is resolved upstream by
        // MenuPreferencesResolver::getCurrencyDisplay() — the macro just has
        // to use it (for the stacked case) rather than the raw ISO $currency.
        $product = $this->product(800);
        $product->setBasePriceLabel('Ración');

        $rows = $this->parseRows($this->render($product, 'USD', true, 'USD')); // USD's symbol "$" is ambiguous, so display = code
        self::assertSame('8.00 USD', $rows[0]['value']);

        $rows = $this->parseRows($this->render($product, 'GBP', true, '£')); // GBP's "£" is unambiguous
        self::assertSame('8.00 £', $rows[0]['value']);
    }

    public function testVariantPricesUseTheCurrencyConvertedAmountNotTheRawOne(): void
    {
        $product = $this->product(800);
        $product->setBasePriceLabel('Ración');
        $product->setConvertedPrice(880); // simulates an 8,00€ base converted to 8.80 USD

        $tapa = new ProductPriceVariant();
        $tapa->setLabel('Tapa');
        $tapa->setPrice(300); // raw cents, restaurant's own currency
        $product->addPriceVariant($tapa);
        $product->setConvertedVariantPrices([$tapa->getId() => 330]); // simulated 3,00€ -> 3.30 USD

        $rows = $this->parseRows($this->render($product, 'USD', true, 'USD'));

        self::assertSame('8.80 USD', $rows[0]['value']);
        self::assertSame('3.30 USD', $rows[1]['value']);
    }

    public function testNullBasePriceLabelRendersItsRowWithNoLabelNode(): void
    {
        // A product that somehow has a variant row but no base label — the
        // base price still renders its own row, just with no
        // ".price-item-label" node at all (not an empty one), so the CSS
        // grid still places the value in the price column correctly.
        $product = $this->product(800);
        $product->setConvertedPrice(800);

        $tapa = new ProductPriceVariant();
        $tapa->setLabel('Tapa');
        $tapa->setPrice(300);
        $product->addPriceVariant($tapa);

        $rows = $this->parseRows($this->render($product, 'EUR', true, '€'));

        self::assertCount(2, $rows);
        self::assertNull($rows[0]['label']);
        self::assertSame('8.00 €', $rows[0]['value']);
        self::assertSame('Tapa', $rows[1]['label']);
    }

    public function testLongLabelIsPresentInMarkupForCssToTruncate(): void
    {
        // Truncation itself (text-overflow: ellipsis) is a rendered-layout
        // effect no headless PHPUnit run can observe — what's testable here
        // is that the macro never shortens the label itself; the full,
        // untouched text reaches the .price-item-label node, exactly as the
        // per-layout CSS (grid column + ellipsis) expects to receive it.
        $product = $this->product(800);
        $product->setBasePriceLabel('Ración especial de la casa muy grande');

        $rows = $this->parseRows($this->render($product, 'EUR', true, '€'));

        self::assertSame('Ración especial de la casa muy grande', $rows[0]['label']);
    }
}
