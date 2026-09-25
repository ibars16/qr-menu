<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\MenuHiddenReason;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Product::menuHiddenReason() is only worth having if it agrees with what a
 * customer actually sees. This renders the REAL public menu (/r/{slug}) for a
 * restaurant holding one dish per hiding reason — including the two shapes
 * of the real €0 dishes that started this ("según mercado"-style, active in
 * an active category but priced 0) — and checks, dish by dish, that the dish
 * is in the HTML exactly when the method says it is shown.
 *
 * Uses the test database only (see doctrine.yaml's dbname_suffix).
 */
final class MenuHiddenReasonMatchesPublicMenuTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;

    /** @var array<string, array{product: Product, reason: ?MenuHiddenReason}> */
    private array $dishes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Hidden Reason Test');
        $this->restaurant->setSlug('hidden-reason-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->em->persist($this->restaurant);

        $normal      = $this->category('Cat normal', active: true);
        $hiddenCat   = $this->category('Cat oculta', active: false);
        $untranslCat = $this->category('Cat sin traducción', active: true, translated: false);
        $menuCat     = $this->category('Menú del día', active: true, fixedPrice: true);

        $this->dish('visible', $normal, 'DishVisibleOk', 1000);
        $this->dish('price_zero', $normal, 'DishPriceZero', 0);
        $this->dish('price_zero_2', $normal, 'DishPriceZeroSecond', 0);
        $this->dish('hidden_dish', $normal, 'DishHiddenByOwner', 1000, active: false);
        $this->dish('no_translation', $normal, null, 1000);
        // Regression (2026-09-25): a translation ROW with an empty string
        // name — as opposed to no row at all — used to read as "has a
        // translation" and render on the public menu with a blank name.
        // dish()'s existing `$name !== null` check already creates the row
        // when $name is '' (only null skips it), so this needs no new helper.
        $this->dish('blank_name', $normal, '', 1000);
        // Regression (2026-09-25, real data): blank default-language (es)
        // name but a named 'en' row — must be hidden, not rendered via the
        // other locale's name while the admin row shows it nameless.
        $this->dish('blank_default_name', $normal, '', 1000, otherLocaleName: 'DishNamedOnlyInEnglish');
        $this->dish('hidden_category', $hiddenCat, 'DishInHiddenCategory', 1000);
        $this->dish('untranslated_category', $untranslCat, 'DishInUntranslatedCategory', 1000);

        $section = new MenuSection();
        $section->setLabel('Primeros');
        $section->setPosition(0);
        $menuCat->addMenuSection($section);
        $this->em->persist($section);
        $this->dish('menu_price_zero', $menuCat, 'DishMenuPriceZero', 0, section: $section);
        $this->dish('menu_hidden_dish', $menuCat, 'DishMenuHiddenByOwner', 0, active: false, section: $section);

        $this->em->flush();

        foreach ($this->dishes as &$entry) {
            $entry['reason'] = $entry['product']->menuHiddenReason();
        }
    }

    protected function tearDown(): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurant->getId());
        if ($restaurant) {
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush(); // dishes first: they reference their section
                foreach ($this->em->getRepository(MenuSection::class)->findBy(['category' => $category]) as $section) {
                    $this->em->remove($section);
                }
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        parent::tearDown();
    }

    private function category(string $name, bool $active, bool $translated = true, bool $fixedPrice = false): Category
    {
        $category = new Category();
        $category->setActive($active);
        if ($fixedPrice) {
            $category->setMenuPrice(1500);
        }
        // addCategory()/addTranslation()/addProduct(): both sides of every
        // relation, same reasoning as MenuContentCacheTest::setUp().
        $this->restaurant->addCategory($category);
        $this->em->persist($category);

        if ($translated) {
            $t = new CategoryTranslation();
            $t->setLocale('es');
            $t->setName($name);
            $category->addTranslation($t);
            $this->em->persist($t);
        }

        return $category;
    }

    private function dish(string $key, Category $category, ?string $name, int $price, bool $active = true, ?MenuSection $section = null, ?string $otherLocaleName = null): void
    {
        $product = new Product();
        $product->setBasePrice($price);
        $product->setActive($active);
        $category->addProduct($product);
        $section?->addProduct($product);
        $this->em->persist($product);

        if ($name !== null) {
            $t = new ProductTranslation();
            $t->setLocale('es');
            $t->setName($name);
            $product->addTranslation($t);
            $this->em->persist($t);
        }

        if ($otherLocaleName !== null) {
            $t = new ProductTranslation();
            $t->setLocale('en');
            $t->setName($otherLocaleName);
            $product->addTranslation($t);
            $this->em->persist($t);
        }

        $this->dishes[$key] = ['product' => $product, 'reason' => null, 'name' => $name];
    }

    public function testEveryDishIsInTheHtmlExactlyWhenTheMethodSaysItIsShown(): void
    {
        $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es');
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        foreach ($this->dishes as $key => $entry) {
            // A dish with no translation has no name to look for; its card
            // (normal categories only) still carries its id.
            // '' would satisfy str_contains() against anything, so an empty
            // name is treated the same as no translation at all: proven by
            // the dish's own card marker being absent, not by an empty needle.
            $needle = ($entry['name'] === null || $entry['name'] === '')
                ? sprintf('data-product-id="%d"', $entry['product']->getId())
                : $entry['name'];

            self::assertSame(
                $entry['reason'] === null,
                str_contains($html, $needle),
                sprintf('"%s": method says %s, public HTML disagrees', $key, $entry['reason']?->value ?? 'shown'),
            );
        }
    }

    /** The es page alone can't prove it: there the blank es row is what the template picks anyway. */
    public function testBlankDefaultNameDishIsHiddenInOtherLocalesToo(): void
    {
        $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=en');
        self::assertResponseIsSuccessful();

        self::assertStringNotContainsString('DishNamedOnlyInEnglish', $this->client->getResponse()->getContent());
    }

    public function testEachReasonIsWhatWeExpect(): void
    {
        $expected = [
            'visible'               => null,
            'price_zero'            => MenuHiddenReason::PriceZero,
            'price_zero_2'          => MenuHiddenReason::PriceZero,
            'hidden_dish'           => MenuHiddenReason::ProductHidden,
            'no_translation'        => MenuHiddenReason::NoTranslation,
            'blank_name'            => MenuHiddenReason::NoTranslation,
            'blank_default_name'    => MenuHiddenReason::NoTranslation,
            'hidden_category'       => MenuHiddenReason::CategoryHidden,
            'untranslated_category' => MenuHiddenReason::NoTranslation,
            'menu_price_zero'       => null,
            'menu_hidden_dish'      => MenuHiddenReason::ProductHidden,
        ];

        foreach ($expected as $key => $reason) {
            self::assertSame($reason, $this->dishes[$key]['reason'], $key);
        }
    }
}
