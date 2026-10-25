<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Renders the actual menu/_set_menu.html.twig partial (shared by all 3
 * public layouts) with the app's real Twig environment, like
 * PriceMacroRenderingTest does for the sibling _price.html.twig macro: the
 * kernel's `twig` service, so every app function/filter (menu_dir,
 * owner_text, asset, trans…) is the real one and a new one can't break
 * this suite. No DB needed — the partial only reads unsaved entities. This
 * suite verifies set-menu structure, not translation text — real
 * translation coverage lives in the menu_public.*.yaml lint + the live
 * render checks done when that i18n work landed.
 */
final class SetMenuRenderingTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = static::getContainer()->get('twig');
    }

    private function restaurant(string $locale = 'es', string $currency = 'EUR'): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setDefaultLanguage($locale);
        $restaurant->setCurrency($currency);

        return $restaurant;
    }

    private function menu(string $name, int $menuPriceCents, ?string $description = null, string $locale = 'es'): Category
    {
        $menu = new Category();
        $menu->setMenuPrice($menuPriceCents);
        $menu->setConvertedMenuPrice($menuPriceCents);
        $menu->setMenuDescription($description);

        $translation = new CategoryTranslation();
        $translation->setLocale($locale);
        $translation->setName($name);
        $menu->addTranslation($translation);

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

    private function dish(MenuSection $section, int $position, string $name, string $locale = 'es'): Product
    {
        $product = new Product();
        $product->setPosition($position);
        $product->setBasePrice(0); // irrelevant inside a set menu — never rendered
        $product->setActive(true);
        // As in the app: a set-menu dish belongs to the menu category too.
        $product->setCategory($section->getCategory());
        $section->addProduct($product);

        $translation = new ProductTranslation();
        $translation->setLocale($locale);
        $translation->setName($name);
        $product->addTranslation($translation);

        return $product;
    }

    private function renderSection(Category $menu, Restaurant $restaurant, string $locale = 'es', string $currencyDisplay = '€'): string
    {
        $menu->setRestaurant($restaurant);
        $template = $this->twig->createTemplate(
            "{% import 'menu/_set_menu.html.twig' as setMenuMacro %}{{ setMenuMacro.section(category, locale, restaurant, currencyDisplay, tagNames, productAllergens) }}"
        );

        return $template->render([
            'category' => $menu,
            'locale' => $locale,
            'restaurant' => $restaurant,
            'currencyDisplay' => $currencyDisplay,
            'tagNames' => [],
            'productAllergens' => [],
        ]);
    }

    private function renderPill(Category $menu, Restaurant $restaurant, string $locale = 'es', string $currencyDisplay = '€'): string
    {
        $menu->setRestaurant($restaurant);
        $template = $this->twig->createTemplate(
            "{% import 'menu/_set_menu.html.twig' as setMenuMacro %}{{ setMenuMacro.pill(category, locale, restaurant, currencyDisplay) }}"
        );

        return trim($template->render([
            'category' => $menu,
            'locale' => $locale,
            'restaurant' => $restaurant,
            'currencyDisplay' => $currencyDisplay,
        ]));
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');

        return new \DOMXPath($dom);
    }

    private function text(\DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);

        return $nodes->length > 0 ? trim($nodes->item(0)->textContent) : null;
    }

    public function testHeaderShowsMenuNameAndConvertedPrice(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu('Menú del día', 1500);
        $this->section($menu, 0, 'Primeros');

        $xpath = $this->xpath($this->renderSection($menu, $restaurant));

        self::assertSame('Menú del día', $this->text($xpath, '//*[@class="set-menu-name"]'));
        self::assertSame('15,00 €', $this->text($xpath, '//*[@class="set-menu-price"]'), 'es: decimal comma (MenuNumberFormatter)');
    }

    public function testDescriptionRendersOnlyWhenSet(): void
    {
        $restaurant = $this->restaurant();

        $withDesc = $this->menu('Menú', 1000, 'Incluye pan y bebida');
        $xpath = $this->xpath($this->renderSection($withDesc, $restaurant));
        self::assertSame('Incluye pan y bebida', $this->text($xpath, '//*[@class="set-menu-desc"]'));

        $withoutDesc = $this->menu('Menú', 1000, null);
        $xpath = $this->xpath($this->renderSection($withoutDesc, $restaurant));
        self::assertNull($this->text($xpath, '//*[@class="set-menu-desc"]'));
    }

    public function testSectionsRenderInPositionOrderAsGroupLabels(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu('Menú del día', 1200);
        $mains = $this->section($menu, 1, 'Segundos');
        $starters = $this->section($menu, 0, 'Primeros');
        $this->dish($starters, 0, 'Sopa');
        $this->dish($mains, 0, 'Filete');

        $xpath = $this->xpath($this->renderSection($menu, $restaurant));
        $labels = $xpath->query('//*[@class="set-menu-group-label"]');

        self::assertSame(2, $labels->length);
        self::assertSame('Primeros', trim($labels->item(0)->textContent));
        self::assertSame('Segundos', trim($labels->item(1)->textContent));
    }

    public function testEmptySectionIsOmittedEntirely(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu('Menú del día', 1200);
        $this->section($menu, 0, 'Postres'); // no dishes added

        $xpath = $this->xpath($this->renderSection($menu, $restaurant));

        self::assertSame(0, $xpath->query('//*[@class="set-menu-group-label"]')->length);
    }

    public function testDishNameRendersWithNoBasePriceAnywhereInTheRow(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu('Menú del día', 1200);
        $section = $this->section($menu, 0, 'Primeros');
        $this->dish($section, 0, 'Ensalada');

        $html = $this->renderSection($menu, $restaurant);
        $xpath = $this->xpath($html);

        self::assertSame('Ensalada', $this->text($xpath, '//*[contains(@class,"set-menu-name") and contains(@class,"product-name")]'));
        // No basePrice/price-variant markup at all inside a dish row — the
        // one price on this whole section is the header's.
        self::assertSame(0, $xpath->query('//*[@class="price-stack"]')->length);
        self::assertStringNotContainsString('front-price', $html);
    }

    public function testSupplementRendersOnlyWhenSet(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu('Menú del día', 1200);
        $section = $this->section($menu, 0, 'Primeros');

        $withSupplement = $this->dish($section, 0, 'Solomillo');
        $withSupplement->setSupplementPrice(300);
        $withSupplement->setConvertedSupplementPrice(300);

        $this->dish($section, 1, 'Sopa'); // no supplement

        $xpath = $this->xpath($this->renderSection($menu, $restaurant));
        $supplements = $xpath->query('//*[@class="set-menu-supplement"]');

        self::assertSame(1, $supplements->length, 'only the dish with a supplement gets a badge');
        self::assertSame('+3,00 €', trim($supplements->item(0)->textContent));
    }

    public function testThumbnailRendersOnlyWhenProductHasAPhotoNoPlaceholder(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu('Menú del día', 1200);
        $section = $this->section($menu, 0, 'Primeros');

        $withPhoto = $this->dish($section, 0, 'Con foto');
        $withPhoto->setImage('con-foto.jpg');
        $this->dish($section, 1, 'Sin foto');

        $xpath = $this->xpath($this->renderSection($menu, $restaurant));

        self::assertSame(1, $xpath->query('//*[@class="set-menu-thumb"]')->length, 'exactly one dish has a photo');
        self::assertSame(2, $xpath->query('//*[contains(@class,"set-menu-name") and contains(@class,"product-name")]')->length, 'both dishes still render their row');
    }

    public function testPillShowsNameAndConvertedPriceSeparatedByMiddleDot(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu('Menú del día', 1500);

        $xpath = $this->xpath($this->renderPill($menu, $restaurant));
        self::assertSame('Menú del día · 15,00 €', $this->text($xpath, '//*[@class="cat-tab cat-tab-menu"]'));
    }

    public function testDishNotActiveIsExcludedFromRendering(): void
    {
        $restaurant = $this->restaurant();
        $menu = $this->menu('Menú del día', 1200);
        $section = $this->section($menu, 0, 'Primeros');
        $this->dish($section, 0, 'Visible')->setActive(true);
        $this->dish($section, 1, 'Oculto')->setActive(false);

        $xpath = $this->xpath($this->renderSection($menu, $restaurant));
        $names = $xpath->query('//*[contains(@class,"set-menu-name") and contains(@class,"product-name")]');

        self::assertSame(1, $names->length);
        self::assertSame('Visible', trim($names->item(0)->textContent));
    }
}
