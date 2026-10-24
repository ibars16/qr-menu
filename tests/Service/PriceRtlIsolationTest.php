<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Service\MenuNumberFormatter;
use App\Repository\OwnerTextTranslationRepository;
use App\Service\MenuPreferencesResolver;
use App\Service\OwnerTextTranslationService;
use App\Service\ProductTranslatorInterface;
use App\Twig\MenuDirectionExtension;
use App\Twig\MenuNumberExtension;
use App\Twig\OwnerTextExtension;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Right-to-left price isolation in menu/_price.html.twig and
 * menu/_set_menu.html.twig: on an RTL menu (menu_dir(locale) == 'rtl',
 * config/languages.php) every amount + currency — and a supplement's
 * leading "+" — is one <bdi dir="ltr" class="price-ltr"> unit; on an LTR
 * menu the markup is exactly what it was before RTL support existed.
 * Standalone Twig with the real menu_number/menu_dir extensions.
 */
final class PriceRtlIsolationTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $this->twig = new Environment(new FilesystemLoader($projectDir . '/templates'));
        $this->twig->addExtension(new MenuNumberExtension(new MenuNumberFormatter()));
        $this->twig->addExtension(new MenuDirectionExtension(new MenuPreferencesResolver($projectDir)));
        // owner_text() with nothing primed: no translations, no source
        // locale — owner texts render as before (dir="auto" on RTL only).
        $this->twig->addExtension(new OwnerTextExtension(
            new OwnerTextTranslationService(
                $this->createStub(OwnerTextTranslationRepository::class),
                $this->createStub(ProductTranslatorInterface::class),
                new NullLogger(),
                $this->createStub(EntityManagerInterface::class),
            ),
            new MenuPreferencesResolver($projectDir),
        ));
        $this->twig->addFunction(new TwigFunction('asset', static fn (string $path) => '/' . $path));
        $this->twig->addFilter(new TwigFilter('trans', static fn (string $id, array $params = [], ?string $domain = null, ?string $locale = null) => $id));
    }

    private function price(string $call, array $vars): string
    {
        return trim($this->twig->createTemplate("{% import 'menu/_price.html.twig' as priceMacro %}{{ priceMacro." . $call . ' }}')->render($vars));
    }

    private function product(int $cents = 1250, ?int $supplement = null): Product
    {
        $product = new Product();
        $product->setBasePrice($cents);
        $product->setConvertedPrice($cents);
        if ($supplement !== null) {
            $product->setSupplementPrice($supplement);
            $product->setConvertedSupplementPrice($supplement);
        }

        return $product;
    }

    private function multiprice(): Product
    {
        $product = $this->product(1550);
        $product->setBasePriceLabel('Botella');
        $variant = new ProductPriceVariant();
        $variant->setLabel('Copa');
        $variant->setPrice(450);
        $variant->setPosition(0);
        $product->addPriceVariant($variant);
        $product->setConvertedVariantPrices([450]);

        return $product;
    }

    public function testSinglePriceIsOneLtrUnitOnRtl(): void
    {
        self::assertSame(
            '<bdi dir="ltr" class="price-ltr">12.50<span class="price-currency">€</span></bdi>',
            $this->price('price(product, "EUR", true, "€", "ar")', ['product' => $this->product()])
        );
    }

    public function testEveryMultipriceValueIsIsolatedInsideItsValueCellAndEveryOwnerLabelIsDirAuto(): void
    {
        $html = $this->price('price(product, "EUR", true, "€", "ar")', ['product' => $this->multiprice()]);

        // Labels are owner text the menu never translates ("Botella" on an
        // Arabic menu): <span dir="auto"> inside the cell, so the cell keeps
        // the page's alignment while the Spanish keeps its own direction.
        self::assertSame(
            '<span class="price-stack">'
            . '<span class="price-row"><span class="price-item-label"><span dir="auto">Botella</span></span><span class="price-item-value"><bdi dir="ltr" class="price-ltr">15.50<span class="price-currency">€</span></bdi></span></span>'
            . '<span class="price-row"><span class="price-item-label"><span dir="auto">Copa</span></span><span class="price-item-value"><bdi dir="ltr" class="price-ltr">4.50<span class="price-currency">€</span></bdi></span></span>'
            . '</span>',
            $html
        );
    }

    public function testFromLabelStaysOutsideTheIsolatedLowestPrice(): void
    {
        self::assertSame(
            '<span class="price-from">dish.price_from</span> <bdi dir="ltr" class="price-ltr">4.50<span class="price-currency">€</span></bdi>',
            $this->price('oneLine(product, "EUR", "€", "ar")', ['product' => $this->multiprice()])
        );
    }

    public function testSupplementPlusSignIsInsideTheUnit(): void
    {
        self::assertSame(
            '<bdi dir="ltr" class="price-ltr"><span class="suppl-value">+3.50</span><span class="price-currency">€</span></bdi>',
            $this->price('supplement(product, "€", "ar")', ['product' => $this->product(1250, 350)])
        );
    }

    public function testArabicScriptCurrencyIsIsolatedWithTheAmount(): void
    {
        self::assertSame(
            '<bdi dir="ltr" class="price-ltr">12.50<span class="price-currency">د.إ</span></bdi>',
            $this->price('price(product, "AED", true, "د.إ", "ar")', ['product' => $this->product()])
        );
    }

    /** @return iterable<string, array{string}> */
    public static function ltrLocales(): iterable
    {
        yield 'es' => ['es'];
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    #[DataProvider('ltrLocales')]
    public function testLtrMenusRenderTheUnwrappedMarkup(string $locale): void
    {
        $vars = ['product' => $this->product(1250, 350), 'multi' => $this->multiprice(), 'locale' => $locale];
        $n = static fn (string $amount) => (new MenuNumberFormatter())->format((float) $amount, $locale);

        self::assertSame($n('12.5') . '<span class="price-currency">€</span>', $this->price('price(product, "EUR", true, "€", locale)', $vars));
        self::assertSame('<span class="suppl-value">+' . $n('3.5') . '</span><span class="price-currency">€</span>', $this->price('supplement(product, "€", locale)', $vars));
        self::assertSame('<span class="price-from">dish.price_from</span> ' . $n('4.5') . '<span class="price-currency">€</span>', $this->price('oneLine(multi, "EUR", "€", locale)', $vars));
        $multi = $this->price('price(multi, "EUR", true, "€", locale)', $vars);
        self::assertStringNotContainsString('<bdi', $multi);
        self::assertStringNotContainsString('dir="auto"', $multi);
        self::assertStringContainsString('<span class="price-item-label">Botella</span>', $multi);
    }

    public function testSetMenuPillHeaderAndSupplementAreIsolatedOnRtlOnly(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setDefaultLanguage('es');
        $menu = new Category();
        $restaurant->addCategory($menu);
        $menu->setMenuPrice(2500);
        $menu->setConvertedMenuPrice(2500);
        foreach (['es' => 'Menú del día', 'ar' => 'قائمة اليوم'] as $locale => $name) {
            $t = new CategoryTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $menu->addTranslation($t);
        }
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $menu->addMenuSection($section);
        $dish = $this->product(0, 300);
        $dish->setActive(true);
        $t = new ProductTranslation();
        $t->setLocale('es');
        $t->setName('Gazpacho');
        $dish->addTranslation($t);
        $menu->addProduct($dish);
        $section->addProduct($dish);

        $render = fn (string $call, string $locale) => $this->twig->createTemplate("{% import 'menu/_set_menu.html.twig' as m %}{{ m." . $call . ' }}')
            ->render(['menu' => $menu, 'locale' => $locale, 'restaurant' => $restaurant]);

        $ar = $render('section(menu, locale, restaurant, "€", [], [])', 'ar');
        self::assertStringContainsString('<div class="set-menu-price"><bdi dir="ltr" class="price-ltr">25.00 €</bdi></div>', $ar);
        self::assertStringContainsString('<div class="set-menu-supplement"><bdi dir="ltr" class="price-ltr">+3.00 €</bdi></div>', $ar);
        self::assertStringContainsString('<i class="ti ti-chevron-left"', $ar);
        self::assertStringContainsString('<div class="set-menu-group-label"><span dir="auto">Primeros</span></div>', $ar);
        self::assertStringContainsString('<div class="set-menu-name product-name"><span dir="auto">Gazpacho</span></div>', $ar, 'no ar translation: the Spanish fallback name');
        self::assertStringContainsString('قائمة اليوم · <bdi dir="ltr" class="price-ltr">25.00 €</bdi></a>', $render('pill(menu, locale, restaurant, "€")', 'ar'));

        $es = $render('section(menu, locale, restaurant, "€", [], [])', 'es');
        self::assertStringContainsString('<div class="set-menu-price">25,00 €</div>', $es);
        self::assertStringContainsString('<div class="set-menu-supplement">+3,00 €</div>', $es);
        self::assertStringContainsString('<i class="ti ti-chevron-right"', $es);
        self::assertStringNotContainsString('<bdi', $es . $render('pill(menu, locale, restaurant, "€")', 'es'));
        self::assertStringNotContainsString('dir="auto"', $es);
    }
}
