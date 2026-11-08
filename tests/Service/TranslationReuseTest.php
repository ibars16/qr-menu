<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Repository\CategoryTranslationRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductTagTranslationRepository;
use App\Repository\ProductTranslationRepository;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\AiCategoryTranslator;
use App\Service\AiProductTranslator;
use App\Service\AiTagTranslator;
use App\Service\RouterCategoryTranslator;
use App\Service\RouterLabelTranslator;
use App\Service\RouterProductTranslator;
use App\Service\RouterTagTranslator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The same source gets the same translation (TranslationReuse): within one
 * run, two dishes / categories / tags with the same source are sent to the
 * AI once and both stored; one with the same source as one already
 * translated is copied, no AI call. Same restaurant only, exact source
 * (language + text; a dish's description too), 'ai' rows inserted only if
 * missing — a translation the owner wrote is never touched.
 *
 * Real provider factory and router against a MockHttpClient answering as
 * Groq, OpenAI or Gemini ("[fr] " + name); only that provider has a (fake)
 * key — no request can reach a real API.
 */
final class TranslationReuseTest extends KernelTestCase
{
    /** provider id => [key env, host] */
    private const PROVIDERS = [
        'groq-llama'        => ['GROQ_API_KEY', 'api.groq.com'],
        'openai-mini'       => ['OPENAI_API_KEY', 'api.openai.com'],
        'gemini-flash-lite' => ['GEMINI_API_KEY', 'generativelanguage.googleapis.com'],
    ];
    private const KEYS = ['GROQ_API_KEY', 'GEMINI_API_KEY', 'OPENAI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY', 'OPENROUTER_API_KEY'];

    private EntityManagerInterface $em;
    private Connection $conn;
    /** @var list<int> */
    private array $restaurantIds = [];
    private array $previousEnv = [];
    private string $provider = 'groq-llama';
    /** @var list<list<string>> source names of each translation request (reviews not counted) */
    private array $sent = [];
    private int $calls = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);
        foreach (self::KEYS as $env) {
            $this->previousEnv[$env] = $_ENV[$env] ?? null;
            $_ENV[$env] = '';
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $env => $value) {
            if ($value === null) {
                unset($_ENV[$env]);
            } else {
                $_ENV[$env] = $value;
            }
        }
        foreach ($this->restaurantIds as $id) {
            $this->conn->executeStatement('DELETE FROM product_translation WHERE product_id IN (SELECT p.id FROM product p JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM product WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category_translation WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category WHERE restaurant_id = ?', [$id]);
            $this->conn->executeStatement('DELETE FROM product_tag_translation WHERE tag_id IN (SELECT id FROM product_tag WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM product_tag WHERE restaurant_id = ?', [$id]);
            $this->conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$id]);
        }

        parent::tearDown();
    }

    public static function providers(): iterable
    {
        foreach (array_keys(self::PROVIDERS) as $id) {
            yield $id => [$id];
        }
    }

    // ── Dishes ────────────────────────────────────────────────────────────

    #[DataProvider('providers')]
    public function testTheSameDishTwiceIsSentOnceAndBothAreStored(string $provider): void
    {
        $this->provider = $provider;
        $r = $this->restaurant();
        $this->dish($r, 'Escalivada');
        $this->dish($r, 'Flan');
        $this->dish($r, 'Escalivada');
        $this->em->flush();

        $stored = $this->translateDishes($r);

        self::assertSame([['Escalivada', 'Flan']], $this->sent);
        self::assertSame(3, $stored);
        self::assertSame(['[fr] Escalivada', '[fr] Flan', '[fr] Escalivada'], $this->dishesIn($r, 'fr'));
    }

    #[DataProvider('providers')]
    public function testADishLikeOneAlreadyTranslatedIsCopiedWithNoAiCall(string $provider): void
    {
        $this->provider = $provider;
        $r = $this->restaurant();
        $this->dish($r, 'Escalivada', 'Con anchoas', fr: ['Escalivada (légumes grillés)', 'Aux anchois']);
        $this->dish($r, 'Escalivada', 'Con anchoas');
        $this->em->flush();
        $version = $this->version($r);

        $stored = $this->translateDishes($r);

        self::assertSame(0, $this->calls, 'nothing left for the AI');
        self::assertSame(1, $stored);
        self::assertSame(
            [['Escalivada (légumes grillés)', 'Aux anchois', 'ai'], ['Escalivada (légumes grillés)', 'Aux anchois', 'ai']],
            $this->conn->fetchAllNumeric("SELECT t.name, t.description, t.source FROM product_translation t JOIN product p ON p.id = t.product_id JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ? AND t.locale = 'fr' ORDER BY p.position", [$r->getId()]),
        );
        self::assertSame($version + 1, $this->version($r), 'the cached menu is refreshed');
    }

    #[DataProvider('providers')]
    public function testADifferentDescriptionOrLanguageIsAnotherSource(string $provider): void
    {
        $this->provider = $provider;
        $r = $this->restaurant();
        $this->dish($r, 'Flan', 'Casero', fr: ['Flan maison', 'Fait maison']);
        $this->dish($r, 'Flan');                          // no description
        $this->dish($r, 'Flan', 'casero');                // another letter case
        $this->dish($r, 'Flan', 'Casero', locale: 'ca');  // written in Catalan
        $this->em->flush();

        $this->translateDishes($r);

        self::assertSame([['Flan', 'Flan', 'Flan']], $this->sent, 'none reused, none merged');
        self::assertSame(['Flan maison', '[fr] Flan', '[fr] Flan', '[fr] Flan'], $this->dishesIn($r, 'fr'));
    }

    #[DataProvider('providers')]
    public function testATranslationTheOwnerWroteIsCopiedButNeverOverwritten(string $provider): void
    {
        $this->provider = $provider;
        $r = $this->restaurant();
        $this->dish($r, 'Flan', fr: ['Notre flan', null], frSource: ProductTranslation::SOURCE_HUMAN);
        $this->dish($r, 'Flan');
        $this->em->flush();

        $this->translateDishes($r);

        self::assertSame(0, $this->calls);
        self::assertSame(
            [['Notre flan', 'human'], ['Notre flan', 'ai']],
            $this->conn->fetchAllNumeric("SELECT t.name, t.source FROM product_translation t JOIN product p ON p.id = t.product_id JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ? AND t.locale = 'fr' ORDER BY p.position", [$r->getId()]),
        );
    }

    #[DataProvider('providers')]
    public function testAnotherRestaurantsTranslationIsNotReused(string $provider): void
    {
        $this->provider = $provider;
        $other = $this->restaurant();
        $this->dish($other, 'Escalivada', fr: ['Escalivade de l\'autre', null]);
        $r = $this->restaurant();
        $this->dish($r, 'Escalivada');
        $this->em->flush();

        $this->translateDishes($r);

        self::assertSame([['Escalivada']], $this->sent);
        self::assertSame(['[fr] Escalivada'], $this->dishesIn($r, 'fr'));
    }

    // ── Categories and tags ───────────────────────────────────────────────

    #[DataProvider('providers')]
    public function testCategoriesWithTheSameNameShareTheirTranslation(string $provider): void
    {
        $this->provider = $provider;
        $r = $this->restaurant();
        $this->category($r, 'Postres', fr: 'Desserts maison');
        $this->category($r, 'Postres');
        $this->category($r, 'Entrantes');
        $this->category($r, 'Entrantes');
        $this->category($r, 'entrantes');
        $other = $this->restaurant();
        $this->category($other, 'Arroces', fr: 'Riz de l\'autre');
        $this->category($r, 'Arroces');
        $this->em->flush();

        $stored = $this->translator(AiCategoryTranslator::class)->translateMissing($this->reload($r), 'fr');

        self::assertSame([['Entrantes', 'entrantes', 'Arroces']], $this->sent);
        self::assertSame(5, $stored);
        self::assertSame(
            ['Desserts maison', 'Desserts maison', '[fr] Entrantes', '[fr] Entrantes', '[fr] entrantes', '[fr] Arroces'],
            $this->conn->fetchFirstColumn("SELECT t.name FROM category_translation t JOIN category c ON c.id = t.category_id WHERE c.restaurant_id = ? AND t.locale = 'fr' ORDER BY c.id", [$r->getId()]),
        );
    }

    #[DataProvider('providers')]
    public function testTagsWithTheSameNameShareTheirTranslation(string $provider): void
    {
        $this->provider = $provider;
        $r = $this->restaurant();
        $this->tag($r, 'Casero', fr: 'Fait maison');
        $this->tag($r, 'Casero');
        $this->tag($r, 'Picante');
        $this->tag($r, 'Picante');
        $this->em->flush();

        $stored = $this->translator(AiTagTranslator::class)->translateMissing($this->reload($r), 'fr');

        self::assertSame([['Picante']], $this->sent);
        self::assertSame(3, $stored);
        self::assertSame(
            [['Fait maison', 'human'], ['Fait maison', 'ai'], ['[fr] Picante', 'ai'], ['[fr] Picante', 'ai']],
            $this->conn->fetchAllNumeric("SELECT t.name, t.source FROM product_tag_translation t JOIN product_tag g ON g.id = t.tag_id WHERE g.restaurant_id = ? AND t.locale = 'fr' ORDER BY g.id", [$r->getId()]),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function translateDishes(Restaurant $r): int
    {
        return $this->translator(AiProductTranslator::class)->translateMissing($this->reload($r), 'fr');
    }

    private function translator(string $class): object
    {
        $_ENV[self::PROVIDERS[$this->provider][0]] = 'test-key-never-sent';
        $http = new MockHttpClient(fn (string $method, string $url, array $options) => $this->answer($url, $options));
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));
        $router = new AIModelRouter($factory, new NullLogger());
        $labels = new RouterLabelTranslator($router, $factory, new NullLogger());
        $c = static::getContainer();

        return match ($class) {
            AiProductTranslator::class => new AiProductTranslator(new RouterProductTranslator($router, $factory, new NullLogger()), $this->em, $c->get(ProductRepository::class), $c->get(ProductTranslationRepository::class), new NullLogger()),
            AiCategoryTranslator::class => new AiCategoryTranslator(new RouterCategoryTranslator($labels), $this->em, $c->get(CategoryTranslationRepository::class), new NullLogger()),
            AiTagTranslator::class => new AiTagTranslator(new RouterTagTranslator($labels), $this->em, $c->get(ProductTagTranslationRepository::class), new NullLogger()),
        };
    }

    /** "[fr] " + name; a review returns its candidate unchanged. */
    private function answer(string $url, array $options): MockResponse
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        self::assertSame(self::PROVIDERS[$this->provider][1], $host);
        $this->calls++;
        $body = json_decode((string) $options['body'], true);
        $gemini = $host === 'generativelanguage.googleapis.com';
        $user = $gemini ? $body['contents'][0]['parts'][0]['text'] : end($body['messages'])['content'];
        [$label, $json] = explode(': ', $user, 2);
        $items = json_decode($json, true);
        if (!isset($items[0]['translated_name'])) {
            $this->sent[] = array_column($items, 'name');
        }
        $result = [];
        foreach ($items as $item) {
            $name = $item['translated_name'] ?? ('[fr] ' . $item['name']);
            $result[$item['id']] = $label === 'Dishes' ? ['name' => $name, 'description' => $item['translated_description'] ?? ''] : $name;
        }
        $content = json_encode($result, JSON_UNESCAPED_UNICODE);

        return $gemini
            ? new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => $content]], 'role' => 'model'], 'finishReason' => 'STOP']]]))
            : new MockResponse(json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']]]));
    }

    private function restaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa reutiliza');
        $restaurant->setSlug('translation-reuse-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setAdminLocale('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIds[] = $restaurant->getId();

        return $restaurant;
    }

    private function category(Restaurant $r, string $name, ?string $fr = null): Category
    {
        $category = new Category();
        $category->setActive(true);
        $r->addCategory($category);
        $this->em->persist($category);
        foreach (array_filter(['es' => $name, 'fr' => $fr]) as $locale => $text) {
            $t = new CategoryTranslation();
            $t->setLocale($locale);
            $t->setName($text);
            $category->addTranslation($t);
            $this->em->persist($t);
        }
        $this->em->flush();

        return $category;
    }

    /** In the restaurant's one "Platos" category (made on first use). */
    private function dish(Restaurant $r, string $name, ?string $description = null, ?array $fr = null, string $frSource = ProductTranslation::SOURCE_AI, string $locale = 'es'): void
    {
        $category = $r->getCategories()->first() ?: $this->category($r, 'Platos', fr: 'Plats');
        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice(900);
        $product->setPosition($category->getProducts()->count() + 1);
        $category->addProduct($product);
        $this->em->persist($product);
        $rows = [[$locale, $name, $description, ProductTranslation::SOURCE_HUMAN]];
        if ($fr !== null) {
            $rows[] = ['fr', $fr[0], $fr[1], $frSource];
        }
        foreach ($rows as [$l, $n, $d, $s]) {
            $t = new ProductTranslation();
            $t->setLocale($l);
            $t->setName($n);
            $t->setDescription($d);
            $t->setSource($s);
            $product->addTranslation($t);
            $this->em->persist($t);
        }
    }

    private function tag(Restaurant $r, string $name, ?string $fr = null): void
    {
        $tag = new ProductTag($r, 'reuse-' . uniqid());
        $r->addProductTag($tag);
        $this->em->persist($tag);
        foreach (array_filter(['es' => $name, 'fr' => $fr]) as $locale => $text) {
            $t = new ProductTagTranslation();
            $t->setLocale($locale);
            $t->setName($text);
            $tag->addTranslation($t);
            $this->em->persist($t);
        }
        $this->em->flush();
    }

    private function reload(Restaurant $r): Restaurant
    {
        $this->em->flush();
        $this->em->clear();

        return $this->em->find(Restaurant::class, $r->getId());
    }

    /** @return list<string> */
    private function dishesIn(Restaurant $r, string $locale): array
    {
        return $this->conn->fetchFirstColumn(
            'SELECT t.name FROM product_translation t JOIN product p ON p.id = t.product_id JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ? AND t.locale = ? ORDER BY p.position',
            [$r->getId(), $locale],
        );
    }

    private function version(Restaurant $r): int
    {
        return (int) $this->conn->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$r->getId()]);
    }
}
