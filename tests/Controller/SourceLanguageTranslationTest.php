<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\Product;
use App\Entity\ProductGlobalIngredient;
use App\Entity\ProductIngredient;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\CategoryTranslatorInterface;
use App\Service\MenuContextBuilder;
use App\Service\ProductTranslatorInterface;
use App\Service\TagTranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Translation never assumes the restaurant writes in Spanish: a French
 * ('fr') and an Italian ('it') restaurant are translated from their own
 * language — or, for a text with no default-language version, from the one
 * it has — and say so (source locales). A Spanish restaurant behaves
 * exactly as before. Every AI is a fake that records its batches and
 * answers "[locale] <text>"; no real call can happen.
 */
final class SourceLanguageTranslationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $slug;
    /** @var int[] */
    private array $restaurantIds = [];
    /** @var int[] */
    private array $globalIngredientIds = [];

    /** dishes and owner texts (in that order, per warm-up) */
    private object $dishAi;
    private object $categoryAi;
    private object $tagAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->dishAi = new class implements ProductTranslatorInterface {
            /** @var list<array{texts: list<string>, sources: list<string>}> */
            public array $batches = [];

            public function translate(array $products, string $targetLocale, array $sourceLocales = []): array
            {
                $this->batches[] = [
                    'texts'   => array_values(array_map(static fn (array $p) => $p['name'], $products)),
                    'sources' => array_values(array_map(static fn ($id) => $sourceLocales[$id] ?? null, array_keys($products))),
                ];

                return array_map(static fn (array $p) => ['name' => "[{$targetLocale}] " . $p['name'], 'description' => null], $products);
            }
        };
        $labelAi = static fn () => new class implements CategoryTranslatorInterface, TagTranslatorInterface {
            /** @var list<array{texts: list<string>, sources: list<string>}> */
            public array $batches = [];

            public function translate(array $names, string $targetLocale, array $sourceLocales = []): array
            {
                $this->batches[] = [
                    'texts'   => array_values($names),
                    'sources' => array_values(array_map(static fn ($id) => $sourceLocales[$id] ?? null, array_keys($names))),
                ];

                return array_map(static fn (string $name) => "[{$targetLocale}] " . $name, $names);
            }
        };
        $this->categoryAi = $labelAi();
        $this->tagAi = $labelAi();
        static::getContainer()->set(ProductTranslatorInterface::class, $this->dishAi);
        static::getContainer()->set(CategoryTranslatorInterface::class, $this->categoryAi);
        static::getContainer()->set(TagTranslatorInterface::class, $this->tagAi);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->restaurantIds as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush();
                $this->em->remove($category);
            }
            foreach ($this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]) as $tag) {
                $this->em->remove($tag);
            }
            foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
                $this->em->remove($ingredient);
            }
            $this->em->flush();
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

    // ── Builders ──────────────────────────────────────────────────────────

    private function restaurant(string $language, ?string $tagline = null, string $adminLocale = 'es'): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa ' . $language);
        $this->slug = 'source-lang-test-' . uniqid();
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage($language);
        $restaurant->setAdminLocale($adminLocale);
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $restaurant->setTagline($tagline);
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIds[] = $restaurant->getId();

        return $restaurant;
    }

    /** @param array<string, string> $names locale => name */
    private function category(Restaurant $restaurant, array $names): Category
    {
        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);
        foreach ($names as $locale => $name) {
            $t = new CategoryTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $category->addTranslation($t);
            $this->em->persist($t);
        }

        return $category;
    }

    /** @param array<string, string> $names locale => name, all written by a person */
    private function dish(Category $category, array $names): Product
    {
        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice(900);
        $category->addProduct($product);
        $this->em->persist($product);
        foreach ($names as $locale => $name) {
            $t = new ProductTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource(ProductTranslation::SOURCE_HUMAN);
            $product->addTranslation($t);
            $this->em->persist($t);
        }

        return $product;
    }

    /** @param array<string, string> $names */
    private function tag(Restaurant $restaurant, array $names): ProductTag
    {
        $tag = new ProductTag($restaurant, 'test-tag-' . uniqid());
        $restaurant->addProductTag($tag);
        $this->em->persist($tag);
        foreach ($names as $locale => $name) {
            $t = new ProductTagTranslation();
            $t->setTag($tag);
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource(ProductTagTranslation::SOURCE_HUMAN);
            $tag->addTranslation($t);
            $this->em->persist($t);
        }

        return $tag;
    }

    /** @param array<string, string> $names */
    private function privateIngredient(Product $product, array $names, int $position): Ingredient
    {
        $ingredient = new Ingredient();
        $ingredient->setCode('test-src-' . uniqid());
        $ingredient->setRestaurant($product->getCategory()->getRestaurant());
        foreach ($names as $locale => $name) {
            $t = new IngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $ingredient->addTranslation($t);
        }
        $this->em->persist($ingredient);
        $link = new ProductIngredient();
        $link->setIngredient($ingredient);
        $link->setPosition($position);
        $product->addIngredientLink($link);
        $this->em->persist($link);

        return $ingredient;
    }

    /** @param array<string, string> $names */
    private function globalIngredient(Product $product, array $names, int $position): GlobalIngredient
    {
        $ingredient = new GlobalIngredient();
        $ingredient->setCode('test-src-global-' . uniqid());
        foreach ($names as $locale => $name) {
            $t = new GlobalIngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $ingredient->addTranslation($t);
        }
        $this->em->persist($ingredient);
        $link = new ProductGlobalIngredient();
        $link->setGlobalIngredient($ingredient);
        $link->setPosition($position);
        $product->addGlobalIngredientLink($link);
        $this->em->persist($link);
        $this->em->flush();
        $this->globalIngredientIds[] = $ingredient->getId();

        return $ingredient;
    }

    /** French bistro: everything written in French by the owner. */
    private function frenchBistro(): Product
    {
        $restaurant = $this->restaurant('fr', 'Cuisine du marché.');
        $category = $this->category($restaurant, ['fr' => 'Entrées']);
        $dish = $this->dish($category, ['fr' => 'Soupe gratinée']);
        $this->tag($restaurant, ['fr' => 'Fait maison']);
        $this->privateIngredient($dish, ['fr' => 'Oignon'], 0);
        $this->em->flush();
        $this->em->clear();

        return $dish;
    }

    // ── Requests ──────────────────────────────────────────────────────────

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

    private function isLoadingScreen(string $html): bool
    {
        return str_contains($html, 'warm-translations');
    }

    /** @return string[] */
    private function detailIngredients(string $html): array
    {
        preg_match_all('#<div class="ing">([^<]*)</div>#', $html, $m);

        return array_map(static fn (string $s) => html_entity_decode($s, ENT_QUOTES, 'UTF-8'), $m[1]);
    }

    /** @return array{name: string, ingredients: string[], category: string} the chat's view of the restaurant's only dish */
    private function chatDish(string $locale): array
    {
        $this->em->clear();
        $restaurant = $this->em->getRepository(Restaurant::class)->find(end($this->restaurantIds));
        $context = static::getContainer()->get(MenuContextBuilder::class)->build($restaurant, $locale, 'EUR');
        $product = $context['categories'][0]['products'][0];

        return ['name' => $product['name'], 'ingredients' => $product['ingredients'], 'category' => $context['categories'][0]['name']];
    }

    /** @return list<array{texts: list<string>, sources: list<string>}> */
    private function sortedBatches(object $ai): array
    {
        return array_map(static function (array $batch) {
            $pairs = array_map(null, $batch['texts'], $batch['sources']);
            sort($pairs);

            return ['texts' => array_column($pairs, 0), 'sources' => array_column($pairs, 1)];
        }, $ai->batches);
    }

    // ── French restaurant ─────────────────────────────────────────────────

    public function testAFrenchRestaurantIsTranslatedFromFrenchAndSaysSo(): void
    {
        $this->frenchBistro();

        self::assertTrue($this->isLoadingScreen($this->render('de', mtw: false)));
        $this->warm('de');

        self::assertSame([
            ['texts' => ['Soupe gratinée'], 'sources' => ['fr']],
            ['texts' => ['Cuisine du marché.', 'Oignon'], 'sources' => ['fr', 'fr']],
        ], $this->sortedBatches($this->dishAi), 'dishes, then owner texts + ingredients');
        self::assertSame([['texts' => ['Entrées'], 'sources' => ['fr']]], $this->categoryAi->batches);
        self::assertSame([['texts' => ['Fait maison'], 'sources' => ['fr']]], $this->tagAi->batches);

        $html = $this->render('de', mtw: false);
        self::assertFalse($this->isLoadingScreen($html), 'everything translated: straight to the menu');
        self::assertStringContainsString('[de] Soupe gratinée', $html);
        self::assertStringContainsString('[de] Entrées', $html);
        self::assertSame(['[de] Oignon'], $this->detailIngredients($html));
        self::assertSame(['name' => '[de] Soupe gratinée', 'ingredients' => ['[de] Oignon'], 'category' => '[de] Entrées'], $this->chatDish('de'));
    }

    public function testAFrenchMenuInFrenchNeedsNoLoadingScreen(): void
    {
        $this->frenchBistro();

        $html = $this->render('fr', mtw: false);

        self::assertFalse($this->isLoadingScreen($html));
        self::assertSame(['Oignon'], $this->detailIngredients($html));
        self::assertSame([], $this->dishAi->batches);
        self::assertSame([], $this->categoryAi->batches);
        self::assertSame([], $this->tagAi->batches);
    }

    public function testAnEnglishOnlyGlobalIngredientLoadsOnceInFrench(): void
    {
        $dish = $this->frenchBistro();
        $dish = $this->em->getRepository(Product::class)->find($dish->getId());
        $this->globalIngredient($dish, ['en' => 'Matcha powder'], 1);
        $this->em->clear();

        self::assertTrue($this->isLoadingScreen($this->render('fr', mtw: false)));
        $this->warm('fr');

        self::assertSame([['texts' => ['Matcha powder'], 'sources' => ['en']]], $this->dishAi->batches);
        $html = $this->render('fr', mtw: false);
        self::assertFalse($this->isLoadingScreen($html), 'once only');
        self::assertSame(['Oignon', '[fr] Matcha powder'], $this->detailIngredients($html));
    }

    public function testADishWithNoDefaultLanguageNameIsTranslatedFromTheOneItHas(): void
    {
        $restaurant = $this->restaurant('fr');
        $category = $this->category($restaurant, ['fr' => 'Desserts', 'de' => 'Nachspeisen']);
        $this->dish($category, ['it' => 'Tiramisù']);
        $this->em->flush();
        $this->em->clear();

        self::assertSame('Tiramisù', $this->chatDish('fr')['name'], 'the chat shows what the menu shows, not an empty name');

        self::assertTrue($this->isLoadingScreen($this->render('de', mtw: false)));
        $this->warm('de');

        self::assertSame([['texts' => ['Tiramisù'], 'sources' => ['it']]], $this->dishAi->batches);
        self::assertFalse($this->isLoadingScreen($html = $this->render('de', mtw: false)), 'not a loading screen on every visit');
        self::assertStringContainsString('[de] Tiramisù', $html);
        self::assertSame('[de] Tiramisù', $this->chatDish('de')['name']);
    }

    public function testADishWithNothingToTranslateFromNeverLoopsTheLoadingScreen(): void
    {
        $restaurant = $this->restaurant('fr');
        $category = $this->category($restaurant, ['fr' => 'Desserts', 'de' => 'Nachspeisen']);
        $this->dish($category, ['fr' => 'Tarte', 'de' => 'Kuchen']);
        $this->dish($category, ['fr' => '   ']);
        $this->em->flush();
        $this->em->clear();

        self::assertFalse($this->isLoadingScreen($this->render('de', mtw: false)));
        self::assertSame([], $this->dishAi->batches);
    }

    // ── Italian restaurant ────────────────────────────────────────────────

    public function testANewIngredientTypedInAnEnglishAdminIsSavedInTheMenusLanguage(): void
    {
        $restaurant = $this->restaurant('it', adminLocale: 'en');
        $category = $this->category($restaurant, ['it' => 'Primi', 'de' => 'Vorspeisen']);
        $dish = $this->dish($category, ['it' => 'Zuppa di cipolle', 'de' => 'Zwiebelsuppe']);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('source-lang-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->find($user->getId()));

        $this->client->request('POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id'           => $dish->getId(),
            'categoryId'   => $category->getId(),
            'basePrice'    => 9.0,
            'translations' => ['it' => ['name' => 'Zuppa di cipolle', 'description' => null]],
            'ingredients'  => [['value' => 'new:Cipolla rossa', 'name' => 'Cipolla rossa']],
        ]));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $saved = $this->em->getRepository(Ingredient::class)->findBy(['restaurant' => end($this->restaurantIds)]);
        self::assertCount(1, $saved);
        self::assertSame(['it'], $saved[0]->getTranslations()->map(static fn (IngredientTranslation $t) => $t->getLocale())->getValues(), 'the menu\'s language, not the admin UI\'s');

        self::assertSame(['Cipolla rossa'], $this->detailIngredients($this->render('it')));
        $this->warm('de');
        self::assertSame([['texts' => ['Cipolla rossa'], 'sources' => ['it']]], $this->dishAi->batches);
        self::assertSame(['[de] Cipolla rossa'], $this->detailIngredients($this->render('de')));
    }

    public function testAGlobalIngredientIsTranslatedFromEnglishAndShownNativeInSpanish(): void
    {
        $restaurant = $this->restaurant('it');
        $category = $this->category($restaurant, ['it' => 'Primi']);
        $dish = $this->dish($category, ['it' => 'Zuppa di cipolle']);
        $this->em->flush();
        $this->globalIngredient($dish, ['en' => 'Onion', 'es' => 'Cebolla'], 0);
        $this->em->clear();

        self::assertTrue($this->isLoadingScreen($this->render('it', mtw: false)));
        $this->warm('it');

        self::assertSame([['texts' => ['Onion'], 'sources' => ['en']]], $this->dishAi->batches);
        self::assertSame(['[it] Onion'], $this->detailIngredients($this->render('it')));
        self::assertSame(['[it] Onion'], $this->chatDish('it')['ingredients']);
        self::assertSame(['Cebolla'], $this->detailIngredients($this->render('es')), 'native Spanish name, never translated');
        self::assertSame(['Cebolla'], $this->chatDish('es')['ingredients']);
    }

    public function testAnIngredientNamedOnlyInAnotherLanguageShowsThatNameNotItsCode(): void
    {
        $restaurant = $this->restaurant('it');
        $category = $this->category($restaurant, ['it' => 'Primi', 'de' => 'Vorspeisen']);
        $dish = $this->dish($category, ['it' => 'Zuppa', 'de' => 'Suppe']);
        $this->privateIngredient($dish, ['ca' => 'Ceba de Figueres'], 0);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['Ceba de Figueres'], $this->detailIngredients($this->render('de')), 'before translating: its name, not its code');
        self::assertSame(['Ceba de Figueres'], $this->chatDish('de')['ingredients']);

        $this->warm('de');

        self::assertSame([['texts' => ['Ceba de Figueres'], 'sources' => ['ca']]], $this->dishAi->batches);
        self::assertSame(['[de] Ceba de Figueres'], $this->detailIngredients($this->render('de')));
    }

    // ── Spanish restaurant: exactly as before ─────────────────────────────

    private function spanishTavern(): void
    {
        $restaurant = $this->restaurant('es', 'Cocina casera.');
        $category = $this->category($restaurant, ['es' => 'Postres']);
        $dish = $this->dish($category, ['es' => 'Tarta de queso']);
        $this->tag($restaurant, ['es' => 'Casero']);
        $this->privateIngredient($dish, ['es' => 'Nata'], 0);
        $this->em->flush();
        $this->globalIngredient($dish, ['es' => 'Huevo', 'en' => 'Egg'], 1);
        $this->em->clear();
    }

    /**
     * Same batches, same texts, same loading screens as before this change —
     * deliberately asserts nothing about source locales (the old code sent
     * none), so it passes against both the old and the new code.
     */
    public function testASpanishRestaurantBehavesExactlyAsBefore(): void
    {
        $this->spanishTavern();

        $html = $this->render('es', mtw: false);
        self::assertFalse($this->isLoadingScreen($html));
        self::assertSame(['Nata', 'Huevo'], $this->detailIngredients($html));
        self::assertTrue($this->isLoadingScreen($this->render('de', mtw: false)));

        $this->warm('de');

        self::assertSame([['Tarta de queso'], ['Cocina casera.', 'Huevo', 'Nata']], array_column($this->sortedBatches($this->dishAi), 'texts'));
        self::assertSame([['Postres']], array_column($this->categoryAi->batches, 'texts'));
        self::assertSame([['Casero']], array_column($this->tagAi->batches, 'texts'));
        $html = $this->render('de', mtw: false);
        self::assertFalse($this->isLoadingScreen($html));
        self::assertStringContainsString('[de] Tarta de queso', $html);
        self::assertStringContainsString('[de] Postres', $html);
        self::assertSame(['[de] Nata', '[de] Huevo'], $this->detailIngredients($html));
        self::assertSame(['name' => '[de] Tarta de queso', 'ingredients' => ['[de] Nata', '[de] Huevo'], 'category' => '[de] Postres'], $this->chatDish('de'));

        $this->warm('de');
        self::assertCount(2, $this->dishAi->batches, 'nothing left to translate');
        self::assertCount(1, $this->categoryAi->batches);
        self::assertCount(1, $this->tagAi->batches);
    }

    public function testASpanishRestaurantSaysItsSourceIsSpanish(): void
    {
        $this->spanishTavern();

        $this->warm('de');

        self::assertSame([['es'], ['es', 'es', 'es']], array_column($this->sortedBatches($this->dishAi), 'sources'));
        self::assertSame([['es']], array_column($this->categoryAi->batches, 'sources'));
        self::assertSame([['es']], array_column($this->tagAi->batches, 'sources'));
    }
}
