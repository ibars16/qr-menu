<?php

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\MenuHiddenReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Product::menuHiddenReason() must say exactly what the public render
 * decides today — it is a read-only mirror of that logic, not a new rule.
 * The first block pins each reason and their order; the second
 * proves equivalence against the REAL filters the public menu uses
 * (Category::getActiveProductsSorted() / getActiveSectionsWithProducts(),
 * the same ones MenuController and show.html.twig call), over every
 * combination of the inputs those filters read.
 */
final class ProductMenuHiddenReasonTest extends TestCase
{
    private function category(bool $active = true, bool $translated = true, bool $fixedPrice = false): Category
    {
        $restaurant = new Restaurant();
        $restaurant->setDefaultLanguage('es');
        $category = new Category();
        $category->setRestaurant($restaurant);
        $category->setActive($active);
        if ($fixedPrice) {
            $category->setMenuPrice(1500);
        }
        if ($translated) {
            $t = new CategoryTranslation();
            $t->setLocale('es');
            $t->setName('Categoría');
            $category->addTranslation($t);
        }

        return $category;
    }

    private function product(Category $category, int $price = 1000, bool $active = true, bool $translated = true, bool $inSection = false): Product
    {
        $product = new Product();
        $product->setBasePrice($price);
        $product->setActive($active);
        if ($translated) {
            $t = new ProductTranslation();
            $t->setLocale('es');
            $t->setName('Plato');
            $product->addTranslation($t);
        }
        $category->addProduct($product);
        if ($inSection) {
            $section = new MenuSection();
            $section->setLabel('Primeros');
            $section->setPosition(0);
            $category->addMenuSection($section);
            $section->addProduct($product); // sets $product->menuSection too
        }

        return $product;
    }

    public function testNormalVisibleDishHasNoReason(): void
    {
        $product = $this->product($this->category());

        self::assertNull($product->menuHiddenReason());
        self::assertTrue($product->isShownOnMenu());
    }

    public function testHiddenCategoryIsReported(): void
    {
        $product = $this->product($this->category(active: false));

        self::assertSame(MenuHiddenReason::CategoryHidden, $product->menuHiddenReason());
        self::assertFalse($product->isShownOnMenu());
    }

    public function testHiddenDishIsReported(): void
    {
        $product = $this->product($this->category(), active: false);

        self::assertSame(MenuHiddenReason::ProductHidden, $product->menuHiddenReason());
    }

    public function testZeroPriceNormalDishIsReported(): void
    {
        $product = $this->product($this->category(), price: 0);

        self::assertSame(MenuHiddenReason::PriceZero, $product->menuHiddenReason());
    }

    public function testDishWithoutTranslationIsReported(): void
    {
        $product = $this->product($this->category(), translated: false);

        self::assertSame(MenuHiddenReason::NoTranslation, $product->menuHiddenReason());
    }

    public function testCategoryWithoutTranslationIsReported(): void
    {
        $product = $this->product($this->category(translated: false));

        self::assertSame(MenuHiddenReason::NoTranslation, $product->menuHiddenReason());
    }

    /**
     * Regression (2026-09-25): a translation ROW existing is not the same as
     * it having text. Before this, `$this->translations->isEmpty()` read a
     * row with an empty string name as "has a translation" — reachable only
     * by writing directly to product_translation outside saveProduct()
     * (which has required a non-blank name since the same date) — so a dish
     * in that state passed as shown, with no admin notice, and rendered on
     * the public menu with a blank name (see
     * MenuHiddenReasonMatchesPublicMenuTest for the real-HTML version of
     * this same case).
     */
    public function testDishWithABlankNameTranslationRowIsReported(): void
    {
        $category = $this->category();
        $product  = $this->product($category, translated: false);
        $blank    = new ProductTranslation();
        $blank->setLocale('es');
        $blank->setName('');
        $product->addTranslation($blank);

        self::assertSame(MenuHiddenReason::NoTranslation, $product->menuHiddenReason());
    }

    /**
     * Regression (2026-09-25, real data): blank name in the restaurant's
     * default language (the one the admin Carta row shows) but a named
     * translation in another language. It used to count as "has a name",
     * so the owner saw a nameless row with no notice.
     */
    public function testBlankDefaultLanguageNameIsReportedEvenWithOtherNamedTranslations(): void
    {
        $product = $this->product($this->category(), translated: false);
        foreach (['es' => '', 'en' => 'Salmon carpaccio'] as $locale => $name) {
            $t = new ProductTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $product->addTranslation($t);
        }

        self::assertSame(MenuHiddenReason::NoTranslation, $product->menuHiddenReason());
        self::assertFalse($product->hasMenuName());
    }

    /** No default-language row at all (e.g. default changed after import): any named translation is enough. */
    public function testMissingDefaultLanguageRowFallsBackToAnyNamedTranslation(): void
    {
        $product = $this->product($this->category(), translated: false);
        $t = new ProductTranslation();
        $t->setLocale('en');
        $t->setName('Salmon carpaccio');
        $product->addTranslation($t);

        self::assertNull($product->menuHiddenReason());
    }

    public function testCategoryWithOnlyABlankNameTranslationRowIsReported(): void
    {
        $category = $this->category(translated: false);
        $blank    = new CategoryTranslation();
        $blank->setLocale('es');
        $blank->setName('');
        $category->addTranslation($blank);
        $product = $this->product($category);

        self::assertSame(MenuHiddenReason::NoTranslation, $product->menuHiddenReason());
    }

    public function testReasonsAreOrderedOutermostFirst(): void
    {
        // Everything wrong at once: the category wins.
        $product = $this->product($this->category(active: false, translated: false), price: 0, active: false, translated: false);
        self::assertSame(MenuHiddenReason::CategoryHidden, $product->menuHiddenReason());

        // Category fine: the dish's own hidden flag wins over price and translation.
        $product = $this->product($this->category(), price: 0, active: false, translated: false);
        self::assertSame(MenuHiddenReason::ProductHidden, $product->menuHiddenReason());

        // Dish active: price wins over a missing translation.
        $product = $this->product($this->category(), price: 0, translated: false);
        self::assertSame(MenuHiddenReason::PriceZero, $product->menuHiddenReason());
    }

    public function testFixedPriceMenuDishPricedAtZeroIsShown(): void
    {
        $product = $this->product($this->category(fixedPrice: true), price: 0, inSection: true);

        self::assertNull($product->menuHiddenReason());
    }

    public function testFixedPriceMenuDishStillHonoursCategoryAndOwnFlag(): void
    {
        $hiddenMenu = $this->product($this->category(active: false, fixedPrice: true), price: 0, inSection: true);
        $hiddenDish = $this->product($this->category(fixedPrice: true), price: 0, active: false, inSection: true);

        self::assertSame(MenuHiddenReason::CategoryHidden, $hiddenMenu->menuHiddenReason());
        self::assertSame(MenuHiddenReason::ProductHidden, $hiddenDish->menuHiddenReason());
    }

    /** @return iterable<string, array{bool, bool, int, bool}> */
    public static function everyCombinationOfWhatThePublicFilterReads(): iterable
    {
        foreach ([true, false] as $categoryActive) {
            foreach ([true, false] as $productActive) {
                foreach ([0, 1000] as $price) {
                    foreach ([false, true] as $fixedPrice) {
                        yield sprintf(
                            'category %s / dish %s / price %d / %s',
                            $categoryActive ? 'active' : 'hidden',
                            $productActive ? 'active' : 'hidden',
                            $price,
                            $fixedPrice ? 'fixed-price menu' : 'normal category',
                        ) => [$categoryActive, $productActive, $price, $fixedPrice];
                    }
                }
            }
        }
    }

    /**
     * "Shown today" = what MenuController/the templates end up rendering:
     * the category must be active (MenuController filters $categories on
     * isActive()), and the dish must survive the category's own public
     * filter — getActiveProductsSorted() for a normal category,
     * getActiveSectionsWithProducts() for a fixed-price menu.
     */
    #[DataProvider('everyCombinationOfWhatThePublicFilterReads')]
    public function testMatchesTheRealPublicFilters(bool $categoryActive, bool $productActive, int $price, bool $fixedPrice): void
    {
        $category = $this->category(active: $categoryActive, fixedPrice: $fixedPrice);
        $product  = $this->product($category, price: $price, active: $productActive, inSection: $fixedPrice);

        if ($fixedPrice) {
            $survivesCategoryFilter = false;
            foreach ($category->getActiveSectionsWithProducts() as $entry) {
                $survivesCategoryFilter = $survivesCategoryFilter || in_array($product, $entry['products'], true);
            }
        } else {
            $survivesCategoryFilter = in_array($product, $category->getActiveProductsSorted(), true);
        }
        $shownToday = $category->isActive() && $survivesCategoryFilter;

        self::assertSame($shownToday, $product->isShownOnMenu());
    }
}
