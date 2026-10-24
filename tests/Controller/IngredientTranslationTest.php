<?php

namespace App\Tests\Controller;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientAllergen;
use App\Entity\IngredientTranslation;
use App\Entity\OwnerTextTranslation;
use App\Entity\Product;
use App\Entity\ProductGlobalIngredient;
use App\Entity\ProductIngredient;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Repository\GlobalIngredientRepository;
use App\Repository\IngredientRepository;
use App\Service\MenuContextBuilder;
use App\Service\ProductAllergenResolver;
use App\Service\ProductTranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ingredient names in the customer's language: translated in the owner-text
 * batch behind the loading screen (OwnerTextTranslationService), stored only
 * in owner_text_translation, shown by the dish detail and Smart Waiter.
 *
 * One dish, three ingredients in printed order: a global one with a native
 * German name ("Ei"), a private Spanish-only one carrying milk ("Nata
 * casera"), and a global English-only one ("Matcha powder"). Same fake AI as
 * OwnerTextTranslationTest: answers "[locale] <text>".
 */
final class IngredientTranslationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?int $restaurantId = null;
    private ?int $productId = null;
    private string $slug;
    /** @var int[] */
    private array $globalIngredientIds = [];

    private object $fakeAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->fakeAi = new class implements ProductTranslatorInterface {
            /** @var list<list<string>> */
            public array $batches = [];

            public function translate(array $products, string $targetLocale, array $sourceLocales = []): array
            {
                $this->batches[] = array_values(array_map(static fn (array $p) => $p['name'], $products));

                return array_map(static fn (array $p) => ['name' => "[{$targetLocale}] " . $p['name'], 'description' => null], $products);
            }
        };
        static::getContainer()->set(ProductTranslatorInterface::class, $this->fakeAi);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->restaurantId ? $this->em->getRepository(Restaurant::class)->find($this->restaurantId) : null;
        if ($restaurant) {
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush();
                $this->em->remove($category);
            }
            foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
                $this->em->remove($ingredient);
            }
            // owner_text_translation rows go with the restaurant (ON DELETE CASCADE).
            $this->em->remove($restaurant);
        }
        foreach ($this->globalIngredientIds as $id) {
            $globalIngredient = $this->em->getRepository(GlobalIngredient::class)->find($id);
            if ($globalIngredient) {
                $this->em->remove($globalIngredient);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function globalIngredient(array $names): GlobalIngredient
    {
        $ingredient = new GlobalIngredient();
        $ingredient->setCode('test-ing-tr-' . uniqid());
        foreach ($names as $locale => $name) {
            $t = new GlobalIngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $ingredient->addTranslation($t);
        }
        $this->em->persist($ingredient);

        return $ingredient;
    }

    private function makeRestaurant(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Ingredientes');
        $this->slug = 'ingredient-tr-test-' . uniqid();
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);
        foreach (['es' => 'Postres', 'de' => 'Desserts'] as $locale => $name) {
            $t = new CategoryTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $category->addTranslation($t);
            $this->em->persist($t);
        }

        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice(800);
        $category->addProduct($product);
        $this->em->persist($product);
        foreach (['es' => 'Tarta verde', 'de' => 'Grüner Kuchen'] as $locale => $name) {
            $t = new ProductTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $product->addTranslation($t);
            $this->em->persist($t);
        }

        $egg    = $this->globalIngredient(['es' => 'Huevo', 'en' => 'Egg', 'de' => 'Ei']);
        $matcha = $this->globalIngredient(['en' => 'Matcha powder']);

        $cream = new Ingredient();
        $cream->setCode('test-nata-' . uniqid());
        $cream->setRestaurant($restaurant);
        $creamName = new IngredientTranslation();
        $creamName->setLocale('es');
        $creamName->setName('Nata casera');
        $cream->addTranslation($creamName);
        $milk = new IngredientAllergen();
        $milk->setAllergen($this->em->getRepository(Allergen::class)->findOneBy(['code' => 'milk']));
        $milk->setPresence(AllergenPresence::CONTAINS);
        $cream->addAllergenLink($milk);
        $this->em->persist($cream);

        // Printed order: Ei (global) · Nata casera (private) · Matcha (global).
        foreach ([[$egg, 0], [$matcha, 2]] as [$ingredient, $position]) {
            $link = new ProductGlobalIngredient();
            $link->setGlobalIngredient($ingredient);
            $link->setPosition($position);
            $product->addGlobalIngredientLink($link);
            $this->em->persist($link);
        }
        $link = new ProductIngredient();
        $link->setIngredient($cream);
        $link->setPosition(1);
        $product->addIngredientLink($link);
        $this->em->persist($link);

        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $this->productId = $product->getId();
        $this->globalIngredientIds = [$egg->getId(), $matcha->getId()];
    }

    private function warm(string $lang): void
    {
        $this->client->request('POST', '/r/' . $this->slug . '/warm-translations?lang=' . $lang);
        self::assertResponseIsSuccessful();
    }

    private function render(string $lang, bool $mtw = true): string
    {
        $this->client->request('GET', '/r/' . $this->slug . '?lang=' . $lang . '&currency=EUR' . ($mtw ? '&mtw=1' : ''));
        self::assertResponseIsSuccessful();

        return $this->client->getResponse()->getContent();
    }

    /** @return string[] the dish detail's ingredient names, in order */
    private function detailIngredients(string $html): array
    {
        preg_match_all('#<div class="ing">([^<]*)</div>#', $html, $m);

        return array_map(static fn (string $s) => html_entity_decode($s, ENT_QUOTES, 'UTF-8'), $m[1]);
    }

    /** @return array<string, array{string, string}> translated text => [source text, source hash], rows into $locale */
    private function stored(string $locale): array
    {
        $rows = $this->em->getRepository(OwnerTextTranslation::class)->findBy(['restaurant' => $this->restaurantId, 'locale' => $locale]);
        $out = [];
        foreach ($rows as $row) {
            $out[$row->getTranslatedText()] = [$row->getSourceText(), $row->getSourceHash()];
        }
        ksort($out);

        return $out;
    }

    private function contentVersion(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$this->restaurantId]);
    }

    /** @return array{int, int} rows in ingredient_translation / global_ingredient_translation */
    private function ingredientTableCounts(): array
    {
        $conn = $this->em->getConnection();

        return [
            (int) $conn->fetchOne('SELECT COUNT(*) FROM ingredient_translation'),
            (int) $conn->fetchOne('SELECT COUNT(*) FROM global_ingredient_translation'),
        ];
    }

    public function testOnlyIngredientsWithoutANativeNameAreTranslatedAndShown(): void
    {
        $this->makeRestaurant();
        $version = $this->contentVersion();

        $this->warm('de');

        self::assertCount(1, $this->fakeAi->batches, 'one batch, shared with the owner texts');
        $batch = $this->fakeAi->batches[0];
        sort($batch);
        self::assertSame(['Matcha powder', 'Nata casera'], $batch, '"Huevo" has a native German name: never sent');
        self::assertSame([
            '[de] Matcha powder' => ['Matcha powder', OwnerTextTranslation::hash('en', 'Matcha powder')],
            '[de] Nata casera'   => ['Nata casera', OwnerTextTranslation::hash('es', 'Nata casera')],
        ], $this->stored('de'), 'the English-only name is hashed with its real source language');
        self::assertSame($version + 1, $this->contentVersion(), 'menu content version bumped');

        self::assertSame(['Ei', '[de] Nata casera', '[de] Matcha powder'], $this->detailIngredients($this->render('de')));

        $this->warm('de');
        self::assertCount(1, $this->fakeAi->batches, 'nothing missing any more: no second batch');
        self::assertSame($version + 1, $this->contentVersion(), 'and no second bump');
    }

    public function testAnEnglishOnlyIngredientIsTranslatedIntoTheDefaultLanguageToo(): void
    {
        $this->makeRestaurant();

        self::assertStringContainsString('warm-translations', $this->render('es', mtw: false), 'the default language goes through the loading screen too');
        self::assertSame(['Huevo', 'Nata casera', 'Matcha powder'], $this->detailIngredients($this->render('es')), 'today\'s fallback until translated');

        $this->warm('es');

        self::assertSame([['Matcha powder']], $this->fakeAi->batches);
        self::assertSame(['[es] Matcha powder' => ['Matcha powder', OwnerTextTranslation::hash('en', 'Matcha powder')]], $this->stored('es'));
        self::assertSame(['Huevo', 'Nata casera', '[es] Matcha powder'], $this->detailIngredients($this->render('es')));
        self::assertStringNotContainsString('warm-translations', $this->render('es', mtw: false), 'nothing missing: straight to the menu');
    }

    public function testTranslatingNeverTouchesIngredientTablesOrAllergens(): void
    {
        $this->makeRestaurant();
        $resolver = static::getContainer()->get(ProductAllergenResolver::class);
        $allergens = fn () => array_map(
            static fn (array $e) => [$e['allergen']->getCode(), $e['presence']->value],
            $resolver->resolveForProducts([$this->em->getRepository(Product::class)->find($this->productId)])[$this->productId] ?? [],
        );
        $tables = $this->ingredientTableCounts();
        $before = $allergens();
        preg_match('#data-product-id="' . $this->productId . '"[^>]*data-allergens="([^"]*)"#', $this->render('de'), $m);
        $attributeBefore = $m[1];

        $this->warm('de');
        $this->em->clear();

        self::assertSame([['milk', 'contains']], $before);
        self::assertSame($before, $allergens(), 'the dish\'s allergens are unchanged');
        preg_match('#data-product-id="' . $this->productId . '"[^>]*data-allergens="([^"]*)"#', $this->render('de'), $m);
        self::assertSame($attributeBefore, $m[1], 'and so is the allergen filter\'s data');
        self::assertSame($tables, $this->ingredientTableCounts(), 'no row written to ingredient_translation / global_ingredient_translation');
    }

    public function testSmartWaiterGetsTheSameNamesInTheSameOrderAsTheMenu(): void
    {
        $this->makeRestaurant();
        $this->warm('de');
        $menu = $this->detailIngredients($this->render('de'));
        $this->em->clear();

        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        $context = static::getContainer()->get(MenuContextBuilder::class)->build($restaurant, 'de', 'EUR');

        self::assertSame(['Ei', '[de] Nata casera', '[de] Matcha powder'], $menu);
        self::assertSame($menu, $context['categories'][0]['products'][0]['ingredients']);
    }

    public function testNameMatchingNeverSeesTheTranslations(): void
    {
        $this->makeRestaurant();
        $this->warm('de');
        $this->em->clear();

        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        $ingredients = static::getContainer()->get(IngredientRepository::class);
        $globals = static::getContainer()->get(GlobalIngredientRepository::class);

        self::assertNotNull($ingredients->findExistingByNameAnyLocale($restaurant, 'Nata casera'), 'sanity: the original name matches');
        self::assertNull($ingredients->findExistingByNameAnyLocale($restaurant, '[de] Nata casera'));
        self::assertNull($globals->findExistingByNameAnyLocale('[de] Matcha powder'));
    }
}
