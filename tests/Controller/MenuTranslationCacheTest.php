<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\Product;
use App\Entity\ProductIngredient;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Service\AiProductTranslator;
use App\Service\CategoryTranslatorInterface;
use App\Service\ProductTranslatorInterface;
use App\Service\TagTranslatorInterface;
use App\Service\TranslationDeadline;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The public menu's translation (MenuController::warmTranslations() and the
 * render after it), against a fake AI that counts its calls — no real call
 * can happen:
 *  - what the loading screen stored is seen by the next load (the menu
 *    content version is bumped), never asked for again;
 *  - the menu render itself never calls the AI;
 *  - a row another visit stored meanwhile is kept, and nothing after it in
 *    the same request breaks;
 *  - batches (25 dishes, 60 names; owner texts and ingredient names in
 *    their own batches), each stored when it works; none started after the
 *    deadline — the next visit asks only for what's left.
 */
final class MenuTranslationCacheTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private MockClock $clock;
    private string $slug;
    private ?int $restaurantId = null;

    private object $dishAi;
    private object $categoryAi;
    private object $tagAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);
        $this->clock = new MockClock();
        static::getContainer()->set('clock', $this->clock);

        $this->dishAi = new class implements ProductTranslatorInterface {
            /** @var list<list<string>> the texts of each call */
            public array $batches = [];
            /** Runs inside translate(), before answering: another visit, a slow AI… */
            public ?\Closure $meanwhile = null;
            /** 1-based numbers of the calls that throw. */
            public array $failCalls = [];

            public function translate(array $products, string $targetLocale, array $sourceLocales = []): array
            {
                $this->batches[] = array_values(array_map(static fn (array $p) => $p['name'], $products));
                if ($this->meanwhile !== null) {
                    ($this->meanwhile)();
                }
                if (in_array(count($this->batches), $this->failCalls, true)) {
                    throw new \RuntimeException('fake AI down');
                }

                return array_map(static fn (array $p) => ['name' => "[{$targetLocale}] " . $p['name'], 'description' => null], $products);
            }
        };
        $labelAi = static fn () => new class implements CategoryTranslatorInterface, TagTranslatorInterface {
            /** @var list<list<string>> */
            public array $batches = [];
            public ?\Closure $meanwhile = null;

            public function translate(array $names, string $targetLocale, array $sourceLocales = []): array
            {
                $this->batches[] = array_values($names);
                if ($this->meanwhile !== null) {
                    ($this->meanwhile)();
                }

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
        if ($this->restaurantId !== null) {
            // Over plain SQL: rows stored straight in the DB aren't in the EM's collections.
            $id = $this->restaurantId;
            $products = 'SELECT p.id FROM product p JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?';
            $this->conn->executeStatement("DELETE FROM product_translation WHERE product_id IN ($products)", [$id]);
            $this->conn->executeStatement("DELETE FROM product_ingredient WHERE product_id IN ($products)", [$id]);
            $this->conn->executeStatement("DELETE FROM product_price_variant WHERE product_id IN ($products)", [$id]);
            $this->conn->executeStatement("DELETE FROM product_tag_assignment WHERE product_id IN ($products)", [$id]);
            $this->conn->executeStatement('DELETE FROM product WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category_translation WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category WHERE restaurant_id = ?', [$id]);
            $this->conn->executeStatement('DELETE FROM ingredient_translation WHERE ingredient_id IN (SELECT id FROM ingredient WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM ingredient WHERE restaurant_id = ?', [$id]);
            $this->conn->executeStatement('DELETE FROM product_tag_translation WHERE tag_id IN (SELECT id FROM product_tag WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM product_tag WHERE restaurant_id = ?', [$id]);
            // owner_text_translation goes with the restaurant (ON DELETE CASCADE).
            $this->conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$id]);
        }

        parent::tearDown();
    }

    // ── The cache: stored = seen ──────────────────────────────────────────

    /**
     * Only the category is missing in French (just renamed, say; the dish
     * already is): loading screen → warm-translations → the menu. Before the
     * fix the next visitor still got the loading screen, the menu reading
     * the cached copy from before the translation.
     */
    public function testWhatTheLoadingScreenStoredIsSeenByTheNextVisit(): void
    {
        $categoryId = $this->spanishMenu(dishInFrench: true);
        $version = $this->version();

        self::assertTrue($this->isLoadingScreen($this->get('fr')), 'first visit: loading screen');
        $this->warm('fr');
        self::assertSame([['Postres']], $this->categoryAi->batches);
        self::assertSame([], $this->dishAi->batches, 'the dish was already in French');
        self::assertGreaterThan($version, $this->version(), 'storing a translation bumps the menu content version');

        self::assertStringContainsString('[fr] Postres', $this->get('fr', mtw: true));
        self::assertFalse($this->isLoadingScreen($this->get('fr')), 'next visitor goes straight to the menu');
        self::assertCount(1, $this->categoryAi->batches, 'never asked again');
        self::assertSame('[fr] Postres', $this->categoryName($categoryId, 'fr'));
    }

    /** Same for a dish (before the fix: asked for again on every load, then a duplicate error). */
    public function testADishTheLoadingScreenStoredIsNeverAskedAgain(): void
    {
        $this->spanishMenu(categoryInFrench: true);

        self::assertTrue($this->isLoadingScreen($this->get('fr')));
        $this->warm('fr');
        self::assertSame([['Flan']], $this->dishAi->batches);

        self::assertStringContainsString('[fr] Flan', $this->get('fr', mtw: true));
        self::assertFalse($this->isLoadingScreen($this->get('fr')));
        $this->warm('fr');
        self::assertCount(1, $this->dishAi->batches);
    }

    // ── The render never translates ───────────────────────────────────────

    /**
     * The menu (?mtw=1, i.e. after a loading screen that didn't make it)
     * shows the default language for what's missing and never calls the
     * AI; the next visit goes through the loading screen again.
     */
    public function testTheMenuItselfNeverCallsTheAi(): void
    {
        $this->spanishMenu(withTag: true);

        $html = $this->get('fr', mtw: true);
        self::assertStringContainsString('Postres', $html);
        self::assertStringContainsString('Flan', $html);
        self::assertSame([], $this->dishAi->batches);
        self::assertSame([], $this->categoryAi->batches);
        self::assertSame([], $this->tagAi->batches);

        self::assertTrue($this->isLoadingScreen($this->get('fr')), 'still missing: loading screen next time');
    }

    // ── Another visit at the same time ────────────────────────────────────

    /**
     * Another visit stores the French dish and category while this one waits
     * for the AI: theirs is kept (here the owner's), no error, and the rest
     * (the tag, translated after them) is still stored.
     */
    public function testRowsStoredMeanwhileByAnotherVisitAreKept(): void
    {
        $categoryId = $this->spanishMenu(withTag: true);
        $productId = (int) $this->conn->fetchOne('SELECT p.id FROM product p JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?', [$this->restaurantId]);
        $conn = $this->conn;
        $this->dishAi->meanwhile = static function () use ($conn, $productId): void {
            $conn->executeStatement("INSERT INTO product_translation (product_id, locale, name, source) VALUES (?, 'fr', 'Flan du chef', 'human')", [$productId]);
        };
        $this->categoryAi->meanwhile = static function () use ($conn, $categoryId): void {
            $conn->executeStatement("INSERT INTO category_translation (category_id, locale, name, source) VALUES (?, 'fr', 'Desserts du chef', 'human')", [$categoryId]);
        };

        $this->warm('fr');

        self::assertSame(['Flan du chef', 'human'], $this->conn->fetchNumeric("SELECT name, source FROM product_translation WHERE product_id = ? AND locale = 'fr'", [$productId]));
        self::assertSame('Desserts du chef', $this->categoryName($categoryId, 'fr'), 'the existing (human) row stays');
        self::assertSame(1, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM product_tag_translation t JOIN product_tag g ON g.id = t.tag_id WHERE g.restaurant_id = ? AND t.locale = 'fr'",
            [$this->restaurantId],
        ), 'the tag, translated after them, is still stored');
        self::assertFalse($this->isLoadingScreen($this->get('fr')));
    }

    // ── Batches ───────────────────────────────────────────────────────────

    public function testSixtyDishesGoInBatchesOfTwentyFive(): void
    {
        $this->spanishMenu(dishes: 60, categoryInFrench: true);

        $this->warm('fr');

        self::assertSame([25, 25, 10], array_map('count', $this->dishAi->batches));
        self::assertSame(60, $this->frenchDishCount());
        self::assertFalse($this->isLoadingScreen($this->get('fr')));
    }

    /** The 2nd batch fails: the 1st and 3rd stay stored, and the next visit asks only for the 2nd's. */
    public function testAFailedBatchLeavesTheOthersStoredAndIsRetriedAlone(): void
    {
        $this->spanishMenu(dishes: 60, categoryInFrench: true);
        $this->dishAi->failCalls = [2];

        $this->warm('fr');
        self::assertSame([25, 25, 10], array_map('count', $this->dishAi->batches));
        self::assertSame(35, $this->frenchDishCount());

        self::assertTrue($this->isLoadingScreen($this->get('fr')), 'still missing: loading screen next time');
        $failed = $this->dishAi->batches[1];
        $this->warm('fr');
        self::assertCount(4, $this->dishAi->batches);
        self::assertSame($failed, $this->dishAi->batches[3], 'only the failed batch is asked for again');
        self::assertSame(60, $this->frenchDishCount());
    }

    /**
     * Each AI call takes 15 s (on the fake clock): the 1st batch starts at
     * 0 s, the 2nd at 15 s, the 3rd would start at 30 s — past the 20 s
     * deadline, so it's left for the next visit, which asks only for it.
     */
    public function testNoBatchStartsAfterTheDeadline(): void
    {
        $this->spanishMenu(dishes: 60, categoryInFrench: true);
        $clock = $this->clock;
        $this->dishAi->meanwhile = static fn () => $clock->sleep(15);

        $this->warm('fr');
        self::assertSame([25, 25], array_map('count', $this->dishAi->batches));
        self::assertSame(50, $this->frenchDishCount());

        $this->warm('fr');
        self::assertSame([25, 25, 10], array_map('count', $this->dishAi->batches), 'next visit: only the rest');
        self::assertSame(60, $this->frenchDishCount());
    }

    /** The deadline itself, on the service: TranslationDeadline::WARM_SECONDS from when it's set. */
    public function testTheDeadlineIsTwentySeconds(): void
    {
        $deadline = TranslationDeadline::in($this->clock, TranslationDeadline::WARM_SECONDS);
        $this->clock->sleep(19.9);
        self::assertFalse($deadline->isPassed());
        $this->clock->sleep(0.1);
        self::assertTrue($deadline->isPassed());
        self::assertSame(25, AiProductTranslator::BATCH);
    }

    /**
     * Owner texts and ingredient names in their own batches: the tagline in
     * one — before the dish — and 70 ingredient names in 60 + 10, after it.
     */
    public function testOwnerTextsAndIngredientNamesGoInTheirOwnBatches(): void
    {
        $this->spanishMenu(categoryInFrench: true, tagline: 'Cocina casera.', ingredients: 70);

        $this->warm('fr');

        self::assertSame([1, 1, 60, 10], array_map('count', $this->dishAi->batches), 'tagline, dish, 60 + 10 ingredient names');
        self::assertSame(['Cocina casera.'], $this->dishAi->batches[0]);
        self::assertSame(['Flan'], $this->dishAi->batches[1]);
        self::assertSame(71, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM owner_text_translation WHERE restaurant_id = ? AND locale = 'fr'", [$this->restaurantId]));
        self::assertFalse($this->isLoadingScreen($this->get('fr')));
    }

    /**
     * The order of the loading screen's work: owner texts (tagline, price
     * labels) → categories → tags → dishes → ingredient names. Each AI call
     * takes 15 s on the fake clock, so the deadline (20 s) passes during the
     * dishes: the tagline and the price labels — done first — are stored
     * anyway, and so are the category and the tag; dishes and ingredient
     * names left after the deadline wait for the next visit. Before, the
     * owner texts came after the dishes and a big menu never reached them
     * (David Test, 58 dishes: tagline and "botella"/"vaso" in Spanish in
     * French and Italian).
     */
    public function testWhenTimeRunsOutInTheDishesTheTaglineAndPriceLabelsAreStillTranslated(): void
    {
        $this->spanishMenu(dishes: 60, withTag: true, tagline: 'Mercado propio tratado con respeto', ingredients: 3, priceLabels: ['botella', 'vaso']);
        $clock = $this->clock;
        $order = [];
        $this->dishAi->meanwhile = static function () use ($clock) { $clock->sleep(15); };
        $this->categoryAi->meanwhile = static function () use (&$order) { $order[] = 'category'; };
        $this->tagAi->meanwhile = static function () use (&$order) { $order[] = 'tag'; };

        $this->warm('fr');

        // 1st call, at 0 s: the owner texts. Categories and tags (no time on
        // the fake clock) at 15 s, then the 1st dish batch at 15 s; at 30 s
        // the deadline has passed: nothing else starts.
        self::assertSame([['Mercado propio tratado con respeto', 'botella', 'vaso'], 25], [$this->dishAi->batches[0], count($this->dishAi->batches[1])]);
        self::assertCount(2, $this->dishAi->batches, 'no 2nd dish batch, no ingredient names: out of time');
        self::assertSame(['category', 'tag'], $order);

        $owner = $this->conn->fetchAllKeyValue("SELECT source_text, translated_text FROM owner_text_translation WHERE restaurant_id = ? AND locale = 'fr' ORDER BY source_text", [$this->restaurantId]);
        self::assertSame(['Mercado propio tratado con respeto' => '[fr] Mercado propio tratado con respeto', 'botella' => '[fr] botella', 'vaso' => '[fr] vaso'], $owner, 'tagline and price labels stored; no ingredient name yet');
        self::assertSame('[fr] Postres', $this->conn->fetchOne("SELECT t.name FROM category_translation t JOIN category c ON c.id = t.category_id WHERE c.restaurant_id = ? AND t.locale = 'fr'", [$this->restaurantId]));
        self::assertSame('[fr] Casero', $this->conn->fetchOne("SELECT t.name FROM product_tag_translation t JOIN product_tag g ON g.id = t.tag_id WHERE g.restaurant_id = ? AND t.locale = 'fr'", [$this->restaurantId]));
        self::assertSame(25, $this->frenchDishCount());

        $html = $this->get('fr', mtw: true);
        self::assertStringContainsString('[fr] Mercado propio tratado con respeto', $html);
        self::assertStringContainsString('[fr] botella', $html);
        self::assertStringContainsString('[fr] vaso', $html);

        // Next visit: only what's left — the other dishes, then the names.
        $this->dishAi->meanwhile = null;
        $this->warm('fr');
        self::assertSame([25, 10, 3], array_map('count', array_slice($this->dishAi->batches, 2)));
        self::assertSame(60, $this->frenchDishCount());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function get(string $locale, bool $mtw = false): string
    {
        $this->em->clear();
        $this->client->request('GET', "/r/{$this->slug}?lang={$locale}" . ($mtw ? '&mtw=1' : ''));
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function warm(string $locale): void
    {
        $this->em->clear();
        $this->client->request('POST', "/r/{$this->slug}/warm-translations?lang={$locale}");
        self::assertResponseIsSuccessful('the warm-up must never break');
        $this->em->clear();
    }

    private function isLoadingScreen(string $html): bool
    {
        return str_contains($html, 'warm-translations');
    }

    private function version(): int
    {
        return (int) $this->conn->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$this->restaurantId]);
    }

    private function frenchDishCount(): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM product_translation t JOIN product p ON p.id = t.product_id JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ? AND t.locale = 'fr'",
            [$this->restaurantId],
        );
    }

    private function categoryName(int $categoryId, string $locale): ?string
    {
        $name = $this->conn->fetchOne('SELECT name FROM category_translation WHERE category_id = ? AND locale = ?', [$categoryId, $locale]);

        return $name === false ? null : $name;
    }

    /**
     * Spanish menu: one category "Postres" (optionally already in French)
     * with $dishes dishes ("Flan", then "Plato 2"… — optionally already in
     * French), optionally one tag "Casero", a tagline and private
     * ingredients on the first dish. Returns the category id.
     */
    private function spanishMenu(
        int $dishes = 1,
        bool $dishInFrench = false,
        bool $categoryInFrench = false,
        bool $withTag = false,
        ?string $tagline = null,
        int $ingredients = 0,
        array $priceLabels = [],
    ): int {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa caché');
        $this->slug = 'menu-cache-test-' . uniqid();
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setAdminLocale('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $restaurant->setTagline($tagline);
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);
        foreach (['es' => 'Postres'] + ($categoryInFrench ? ['fr' => 'Desserts'] : []) as $locale => $name) {
            $t = new CategoryTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($locale === 'es' ? CategoryTranslation::SOURCE_HUMAN : CategoryTranslation::SOURCE_AI);
            $category->addTranslation($t);
            $this->em->persist($t);
        }

        $first = null;
        for ($i = 1; $i <= $dishes; $i++) {
            $product = new Product();
            $product->setActive(true);
            $product->setBasePrice(900);
            $product->setPosition($i);
            $category->addProduct($product);
            $this->em->persist($product);
            $first ??= $product;
            $name = $i === 1 ? 'Flan' : "Plato {$i}";
            foreach (['es' => $name] + ($dishInFrench ? ['fr' => $name] : []) as $locale => $text) {
                $t = new ProductTranslation();
                $t->setLocale($locale);
                $t->setName($text);
                $t->setSource($locale === 'es' ? ProductTranslation::SOURCE_HUMAN : ProductTranslation::SOURCE_AI);
                $product->addTranslation($t);
                $this->em->persist($t);
            }
        }

        // Price labels on the 1st dish: the base price's, then one variant each.
        if ($priceLabels !== []) {
            $first->setBasePriceLabel(array_shift($priceLabels));
            foreach ($priceLabels as $i => $label) {
                $variant = new ProductPriceVariant();
                $variant->setLabel($label);
                $variant->setPrice(2500 + $i);
                $variant->setPosition($i);
                $first->addPriceVariant($variant);
            }
        }

        for ($i = 1; $i <= $ingredients; $i++) {
            $ingredient = new Ingredient();
            $ingredient->setCode('test-cache-' . uniqid() . "-{$i}");
            $ingredient->setRestaurant($restaurant);
            $t = new IngredientTranslation();
            $t->setLocale('es');
            $t->setName("Ingrediente {$i}");
            $ingredient->addTranslation($t);
            $this->em->persist($ingredient);
            $link = new ProductIngredient();
            $link->setIngredient($ingredient);
            $link->setPosition($i);
            $first->addIngredientLink($link);
            $this->em->persist($link);
        }

        if ($withTag) {
            $tag = new ProductTag($restaurant, 'test-tag-' . uniqid());
            $restaurant->addProductTag($tag);
            $this->em->persist($tag);
            $t = new ProductTagTranslation();
            $t->setTag($tag);
            $t->setLocale('es');
            $t->setName('Casero');
            $t->setSource(ProductTagTranslation::SOURCE_HUMAN);
            $tag->addTranslation($t);
            $this->em->persist($t);
        }

        $this->em->flush();
        $categoryId = $category->getId();
        $this->em->clear();

        return $categoryId;
    }
}
