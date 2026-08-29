<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Repository\AllergenRepository;
use App\Repository\ExchangeRateRepository;
use App\Service\CurrencyConverter;
use App\Service\MenuContextBuilder;
use App\Service\ProductAllergenResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Covers the Smart Waiter data shape for fixed-price ("set") menu
 * categories — MenuContextBuilder::build()'s 'menu_price'/'menu_description'/
 * 'sections' branch, as opposed to the normal category's 'products' shape.
 * No kernel/DB: ProductAllergenResolver is mocked exactly like any other
 * collaborator, since only its return shape (keyed by product id) matters
 * here, not its own resolution logic (already covered by its own tests).
 */
final class MenuContextBuilderSetMenuTest extends TestCase
{
    private function restaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Test');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');

        return $restaurant;
    }

    private function menu(Restaurant $restaurant, string $name, int $menuPriceCents, ?string $description = null): Category
    {
        $menu = new Category();
        $menu->setRestaurant($restaurant);
        $menu->setActive(true);
        $menu->setMenuPrice($menuPriceCents);
        $menu->setMenuDescription($description);

        $translation = new CategoryTranslation();
        $translation->setLocale('es');
        $translation->setName($name);
        $menu->addTranslation($translation);

        $restaurant->addCategory($menu);

        return $menu;
    }

    private function section(Category $menu, int $position, string $label): MenuSection
    {
        $section = new MenuSection();
        $section->setLabel($label);
        $section->setPosition($position);
        $menu->addMenuSection($section);

        return $section;
    }

    private function dish(MenuSection $section, int $position, string $name): Product
    {
        $product = new Product();
        $product->setPosition($position);
        $product->setBasePrice(0);
        $product->setActive(true);
        $section->addProduct($product);

        $translation = new ProductTranslation();
        $translation->setLocale('es');
        $translation->setName($name);
        $product->addTranslation($translation);

        return $product;
    }

    /**
     * ProductAllergenResolver is `final`, so it can't be mocked directly —
     * construct the real thing with stubbed collaborators instead. Every
     * fixture Product here is unpersisted (getId() === null), so
     * resolveForProducts() takes its own empty-id early return and never
     * touches the EntityManager/AllergenRepository at all — this test isn't
     * exercising allergen resolution, only the set-menu shape around it.
     *
     * Every build() call below passes 'EUR' as the target currency, matching
     * restaurant()'s own base currency — CurrencyConverter::convert() short-
     * circuits on same-currency before ever touching ExchangeRateRepository,
     * which is why a stub with no configured behavior is safe here.
     */
    private function builder(): MenuContextBuilder
    {
        $allergenResolver = new ProductAllergenResolver(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AllergenRepository::class),
            $this->createStub(CacheItemPoolInterface::class),
        );
        $currencyConverter = new CurrencyConverter($this->createStub(ExchangeRateRepository::class));

        return new MenuContextBuilder($allergenResolver, $currencyConverter);
    }

    public function testSetMenuCategoryEmitsMenuPriceAndDescriptionInsteadOfProducts(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu($restaurant, 'Menú del día', 1500, 'Incluye pan y bebida');
        $section = $this->section($menu, 0, 'Primeros');
        $this->dish($section, 0, 'Sopa');

        $context = $this->builder()->build($restaurant, 'es', 'EUR');

        self::assertCount(1, $context['categories']);
        $category = $context['categories'][0];

        self::assertArrayNotHasKey('products', $category);
        self::assertEquals(15, $category['menu_price'], 'decimal euros, same convention as getBasePriceDecimal()');
        self::assertSame('Incluye pan y bebida', $category['menu_description']);
    }

    public function testDishesAreGroupedBySectionLabelInPositionOrder(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu($restaurant, 'Menú del día', 1200);
        $mains = $this->section($menu, 1, 'Segundos');
        $starters = $this->section($menu, 0, 'Primeros');
        $this->dish($starters, 0, 'Sopa');
        $this->dish($mains, 0, 'Filete');

        $context = $this->builder()->build($restaurant, 'es', 'EUR');
        $sections = $context['categories'][0]['sections'];

        self::assertCount(2, $sections);
        self::assertSame('Primeros', $sections[0]['label']);
        self::assertSame(['Sopa'], array_column($sections[0]['dishes'], 'name'));
        self::assertSame('Segundos', $sections[1]['label']);
        self::assertSame(['Filete'], array_column($sections[1]['dishes'], 'name'));
    }

    public function testDishOmitsPriceAndPriceVariantsAndCarriesPartOfSetMenuMarker(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu($restaurant, 'Menú del día', 1200);
        $section = $this->section($menu, 0, 'Primeros');
        $this->dish($section, 0, 'Sopa');

        $context = $this->builder()->build($restaurant, 'es', 'EUR');
        $dish = $context['categories'][0]['sections'][0]['dishes'][0];

        self::assertArrayNotHasKey('price', $dish);
        self::assertArrayNotHasKey('price_variants', $dish);
        self::assertTrue($dish['part_of_set_menu']);
        self::assertNull($dish['supplement'], 'no supplement set on this dish');
    }

    public function testDishSupplementIsEmittedAsADecimalWhenSet(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu($restaurant, 'Menú del día', 1200);
        $section = $this->section($menu, 0, 'Primeros');
        $dish = $this->dish($section, 0, 'Solomillo');
        $dish->setSupplementPrice(350);

        $context = $this->builder()->build($restaurant, 'es', 'EUR');

        self::assertSame(3.5, $context['categories'][0]['sections'][0]['dishes'][0]['supplement']);
    }

    public function testEmptySectionIsOmittedAndAMenuWithNoActiveDishesIsOmittedEntirely(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu($restaurant, 'Menú del día', 1200);
        $this->section($menu, 0, 'Postres'); // no dishes

        $context = $this->builder()->build($restaurant, 'es', 'EUR');

        self::assertSame([], $context['categories'], 'a set menu with zero active dishes never reaches Smart Waiter');
    }

    public function testInactiveDishIsExcluded(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu($restaurant, 'Menú del día', 1200);
        $section = $this->section($menu, 0, 'Primeros');
        $this->dish($section, 0, 'Visible');
        $this->dish($section, 1, 'Oculto')->setActive(false);

        $context = $this->builder()->build($restaurant, 'es', 'EUR');

        self::assertSame(['Visible'], array_column($context['categories'][0]['sections'][0]['dishes'], 'name'));
    }

    public function testNormalCategoryShapeIsUnaffected(): void
    {
        $restaurant = $this->restaurant();
        $category = new Category();
        $category->setRestaurant($restaurant);
        $category->setActive(true);
        $translation = new CategoryTranslation();
        $translation->setLocale('es');
        $translation->setName('Entrantes');
        $category->addTranslation($translation);
        $restaurant->addCategory($category);

        $product = new Product();
        $product->setBasePrice(500);
        $product->setActive(true);
        $product->setCategory($category);
        $pT = new ProductTranslation();
        $pT->setLocale('es');
        $pT->setName('Croquetas');
        $product->addTranslation($pT);
        $category->addProduct($product);

        $context = $this->builder()->build($restaurant, 'es', 'EUR');
        $result = $context['categories'][0];

        self::assertArrayNotHasKey('menu_price', $result);
        self::assertArrayNotHasKey('sections', $result);
        self::assertSame(5.0, $result['products'][0]['price']);
    }
}
