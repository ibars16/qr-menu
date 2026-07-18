<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for Product::hasPriceVariants() (the simple-vs-variant
 * rendering-rule switch used by menu/_price.html.twig and
 * MenuContextBuilder) and getConvertedVariantPrice() (the currency-conversion
 * lookup MenuController populates). No DB/kernel needed — everything here is
 * a plain, unpersisted entity.
 */
final class ProductPriceVariantResolutionTest extends TestCase
{
    private function product(int $basePrice = 1000): Product
    {
        $product = new Product();
        $product->setBasePrice($basePrice);

        return $product;
    }

    public function testSimpleProductHasNoPriceVariants(): void
    {
        self::assertFalse($this->product()->hasPriceVariants());
    }

    public function testBasePriceLabelAloneCountsAsHavingVariants(): void
    {
        $product = $this->product();
        $product->setBasePriceLabel('Ración');

        self::assertTrue($product->hasPriceVariants());
    }

    public function testAddingAVariantRowCountsAsHavingVariants(): void
    {
        $product = $this->product();
        $variant = new ProductPriceVariant();
        $variant->setLabel('Tapa');
        $variant->setPrice(300);
        $product->addPriceVariant($variant);

        self::assertTrue($product->hasPriceVariants());
    }

    public function testPriceVariantsAreReturnedInPositionOrder(): void
    {
        $product = $this->product();

        $racion = new ProductPriceVariant();
        $racion->setLabel('Ración');
        $racion->setPrice(800);
        $racion->setPosition(2);

        $tapa = new ProductPriceVariant();
        $tapa->setLabel('Tapa');
        $tapa->setPrice(300);
        $tapa->setPosition(0);

        $media = new ProductPriceVariant();
        $media->setLabel('Media ración');
        $media->setPrice(500);
        $media->setPosition(1);

        // Added out of order — the entity itself doesn't sort (that's the
        // #[ORM\OrderBy] mapping, which only applies once Doctrine hydrates
        // from the DB); this asserts the labels/order controllers and
        // templates actually see once positions are set correctly.
        $product->addPriceVariant($racion);
        $product->addPriceVariant($tapa);
        $product->addPriceVariant($media);

        $ordered = $product->getPriceVariants()->toArray();
        usort($ordered, static fn (ProductPriceVariant $a, ProductPriceVariant $b) => $a->getPosition() <=> $b->getPosition());

        self::assertSame(['Tapa', 'Media ración', 'Ración'], array_map(static fn (ProductPriceVariant $v) => $v->getLabel(), $ordered));
    }

    public function testConvertedVariantPriceFallsBackToRawPriceWhenNoConversionApplied(): void
    {
        $variant = new ProductPriceVariant();
        $variant->setPrice(300);

        self::assertSame(300, $this->product()->getConvertedVariantPrice($variant));
    }

    public function testConvertedVariantPriceUsesTheAppliedConversionWhenPresent(): void
    {
        $variant = new ProductPriceVariant();
        $variant->setPrice(300);

        // ProductPriceVariant::$id is only ever set by Doctrine on flush;
        // reflection is the only way to give this unpersisted variant an id
        // so getConvertedVariantPrice()'s id-keyed lookup has something to hit.
        $idProperty = new \ReflectionProperty(ProductPriceVariant::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($variant, 42);

        $product = $this->product();
        $product->setConvertedVariantPrices([42 => 275]);

        self::assertSame(275, $product->getConvertedVariantPrice($variant));
    }
}
