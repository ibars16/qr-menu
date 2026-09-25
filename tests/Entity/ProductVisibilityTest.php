<?php

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use PHPUnit\Framework\TestCase;

/**
 * A normal (non fixed-price-menu) dish priced at €0 is almost always a
 * data-entry slip, not an intentional free item — see
 * Product::isSafeToDisplay()'s docblock — so it must never reach the
 * public menu even while $active stays true. Fixed-price-menu dishes are
 * exempt: their base price is legitimately 0.
 */
final class ProductVisibilityTest extends TestCase
{
    private function productWithPrice(int $basePriceCents, bool $active = true, ?MenuSection $menuSection = null): Product
    {
        $product = new Product();
        $product->setBasePrice($basePriceCents);
        $product->setActive($active);
        $product->setMenuSection($menuSection);
        // Named, so getActiveProductsSorted()'s hasMenuName() check never
        // interferes with what these tests are about (price/active).
        $t = new ProductTranslation();
        $t->setLocale('es');
        $t->setName('Plato');
        $product->addTranslation($t);

        return $product;
    }

    private function category(): Category
    {
        $restaurant = new Restaurant();
        $restaurant->setDefaultLanguage('es');
        $category = new Category();
        $category->setRestaurant($restaurant);

        return $category;
    }

    public function testNormalDishWithPositivePriceIsSafeToDisplay(): void
    {
        $product = $this->productWithPrice(1250);

        self::assertTrue($product->isSafeToDisplay());
    }

    public function testNormalDishPricedAtZeroIsNotSafeToDisplay(): void
    {
        $product = $this->productWithPrice(0);

        self::assertFalse($product->isSafeToDisplay());
    }

    public function testFixedPriceMenuDishPricedAtZeroIsExempt(): void
    {
        $menuSection = new MenuSection();
        $product     = $this->productWithPrice(0, true, $menuSection);

        self::assertTrue($product->isSafeToDisplay());
    }

    public function testCategoryHidesActiveZeroPriceDishFromPublicMenu(): void
    {
        $category   = $this->category();
        $zeroPriced = $this->productWithPrice(0);
        $priced     = $this->productWithPrice(1000);
        $category->addProduct($zeroPriced);
        $category->addProduct($priced);

        $visible = $category->getActiveProductsSorted();

        self::assertSame([$priced], $visible);
    }

    public function testCategoryStillHidesInactiveDishRegardlessOfPrice(): void
    {
        $category = $this->category();
        $inactive = $this->productWithPrice(1000, false);
        $category->addProduct($inactive);

        self::assertSame([], $category->getActiveProductsSorted());
    }
}
