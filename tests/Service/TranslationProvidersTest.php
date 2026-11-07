<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Repository\CategoryTranslationRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductTranslationRepository;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\AiCategoryTranslator;
use App\Service\AiProductTranslator;
use App\Service\RouterCategoryTranslator;
use App\Service\RouterLabelTranslator;
use App\Service\RouterProductTranslator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The menu's dish and category translation (AiProductTranslator /
 * AiCategoryTranslator, down to the stored rows) works the same whichever
 * AI answers: Groq, Gemini, and every provider the router can fall back to —
 * OpenAI, Mistral, DeepSeek, OpenRouter. Each one answers in its own format
 * and marks a cut-off / interrupted answer its own way; only an answer that
 * ended normally is stored.
 *
 * Real provider factory and router (config/ai_providers.yaml), against a
 * MockHttpClient answering in each provider's wire format; only the keys a
 * test names are set (fake values), the others blanked — no request can
 * reach a real API.
 */
final class TranslationProvidersTest extends KernelTestCase
{
    /** provider id => [key env, host, wire format] */
    private const PROVIDERS = [
        'groq-llama'        => ['GROQ_API_KEY', 'api.groq.com', 'openai'],
        'gemini-flash-lite' => ['GEMINI_API_KEY', 'generativelanguage.googleapis.com', 'gemini'],
        'openai-mini'       => ['OPENAI_API_KEY', 'api.openai.com', 'openai'],
        'mistral-large'     => ['MISTRAL_API_KEY', 'api.mistral.ai', 'openai'],
        'deepseek-chat'     => ['DEEPSEEK_API_KEY', 'api.deepseek.com', 'openai'],
        'openrouter-free'   => ['OPENROUTER_API_KEY', 'openrouter.ai', 'openai'],
    ];

    private EntityManagerInterface $em;
    private Connection $conn;
    private ?int $restaurantId = null;
    private array $previousEnv = [];
    /** @var list<string> hosts actually requested, in order */
    private array $hosts = [];
    /** host => how it answers: finish reason (as that provider writes it), an HTTP error code, or 'parts' (Gemini, text split in two) */
    private array $answers = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);
        foreach (self::PROVIDERS as [$env]) {
            $this->previousEnv[$env] = $_ENV[$env] ?? null;
            $_ENV[$env] = ''; // blank = not configured (AIProviderFactory)
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
        if ($this->restaurantId !== null) {
            $id = $this->restaurantId;
            $this->conn->executeStatement('DELETE FROM product_translation WHERE product_id IN (SELECT p.id FROM product p JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM product WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category_translation WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category WHERE restaurant_id = ?', [$id]);
            $this->conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$id]);
        }

        parent::tearDown();
    }

    // ── Every provider ────────────────────────────────────────────────────

    public static function providers(): iterable
    {
        foreach (array_keys(self::PROVIDERS) as $id) {
            yield $id => [$id];
        }
    }

    /** How each provider says "cut off / interrupted" (as it writes it on the wire). */
    public static function cutOffAnswers(): iterable
    {
        $reasons = [
            'groq-llama'        => ['length'],
            'gemini-flash-lite' => ['MAX_TOKENS', 'SAFETY', 'RECITATION', 'OTHER'],
            'openai-mini'       => ['length', 'content_filter'],
            'mistral-large'     => ['length', 'model_length', 'error'],
            'deepseek-chat'     => ['length', 'insufficient_system_resource'],
            'openrouter-free'   => ['length', 'error'],
        ];
        foreach ($reasons as $id => $list) {
            foreach ($list as $reason) {
                yield "{$id} {$reason}" => [$id, $reason];
            }
        }
    }

    #[DataProvider('providers')]
    public function testAGoodAnswerIsStored(string $provider): void
    {
        [, $host, $format] = self::PROVIDERS[$provider];
        $this->only($provider, $format === 'gemini' ? 'STOP' : 'stop');
        $this->menu(dishes: 1);

        $this->translate();

        self::assertSame(['[de] Flan'], $this->storedDishes());
        self::assertSame(['[de] Postres'], $this->storedCategories());
        self::assertSame(array_fill(0, 4, $host), $this->hosts, 'dish + category, each translated and checked, all by this provider');
    }

    #[DataProvider('cutOffAnswers')]
    public function testACutOffAnswerIsNotStored(string $provider, string $reason): void
    {
        [, $host] = self::PROVIDERS[$provider];
        $this->only($provider, $reason);
        $this->menu(dishes: 1);

        $this->translate();

        self::assertSame([], $this->storedDishes());
        self::assertSame([], $this->storedCategories());
        self::assertSame([$host, $host], $this->hosts, 'one call each, not checked and not sent again');
    }

    /** A cut-off check pass (2nd call) keeps the first pass, whatever the provider. */
    #[DataProvider('providers')]
    public function testACutOffCheckPassKeepsTheFirstPass(string $provider): void
    {
        [$env, $host, $format] = self::PROVIDERS[$provider];
        $_ENV[$env] = 'test-key-never-sent';
        $ok = $format === 'gemini' ? 'STOP' : 'stop';
        $cut = $format === 'gemini' ? 'MAX_TOKENS' : 'length';
        $this->answers[$host] = [$ok, $cut, $ok, $cut];
        $this->menu(dishes: 1);

        $this->translate();

        self::assertSame(['[de] Flan'], $this->storedDishes());
        self::assertSame(['[de] Postres'], $this->storedCategories());
    }

    // ── Falling back ──────────────────────────────────────────────────────

    public function testWhenGroqFailsGeminiTranslatesAndItIsStored(): void
    {
        $_ENV['GROQ_API_KEY'] = $_ENV['GEMINI_API_KEY'] = 'test-key-never-sent';
        $this->answers = ['api.groq.com' => 503, 'generativelanguage.googleapis.com' => 'STOP'];
        $this->menu(dishes: 1);

        $this->translate();

        self::assertSame(['[de] Flan'], $this->storedDishes());
        self::assertSame(['[de] Postres'], $this->storedCategories());
        self::assertSame(
            array_merge(...array_fill(0, 4, ['api.groq.com', 'generativelanguage.googleapis.com'])),
            $this->hosts,
            'every call: Groq first, then Gemini',
        );
    }

    public function testWhenGroqIsOutOfQuotaGeminiTranslatesAndItIsStored(): void
    {
        $_ENV['GROQ_API_KEY'] = $_ENV['GEMINI_API_KEY'] = 'test-key-never-sent';
        $this->answers = ['api.groq.com' => 429, 'generativelanguage.googleapis.com' => 'STOP'];
        $this->menu(dishes: 1);

        $this->translate();

        self::assertSame(['[de] Flan'], $this->storedDishes());
        self::assertSame(['[de] Postres'], $this->storedCategories());
    }

    public function testWhenGroqFailsAndGeminiIsCutOffNothingIsStored(): void
    {
        $_ENV['GROQ_API_KEY'] = $_ENV['GEMINI_API_KEY'] = 'test-key-never-sent';
        $this->answers = ['api.groq.com' => 503, 'generativelanguage.googleapis.com' => 'MAX_TOKENS'];
        $this->menu(dishes: 1);

        $this->translate();

        self::assertSame([], $this->storedDishes());
        self::assertSame([], $this->storedCategories());
    }

    /** Groq answered but cut off: not used, and not sent to Gemini (it would cost twice). */
    public function testACutOffGroqAnswerIsNotSentToGeminiToo(): void
    {
        $_ENV['GROQ_API_KEY'] = $_ENV['GEMINI_API_KEY'] = 'test-key-never-sent';
        $this->answers = ['api.groq.com' => 'length', 'generativelanguage.googleapis.com' => 'STOP'];
        $this->menu(dishes: 1);

        $this->translate();

        self::assertSame([], $this->storedDishes());
        self::assertSame(['api.groq.com', 'api.groq.com'], $this->hosts);
    }

    /** Gemini may split a long answer into several text parts: all of them are the answer. */
    public function testAGeminiAnswerSplitInPartsIsReadWhole(): void
    {
        $_ENV['GEMINI_API_KEY'] = 'test-key-never-sent';
        $this->answers = ['generativelanguage.googleapis.com' => 'parts'];
        $this->menu(dishes: 1);

        $this->translate();

        self::assertSame(['[de] Flan'], $this->storedDishes());
        self::assertSame(['[de] Postres'], $this->storedCategories());
    }

    // ── Batches and version: not the provider's business ──────────────────

    /** 30 dishes through Mistral: 25 + 5, both stored, each batch bumping the menu content version. */
    public function testBatchesAndTheVersionBumpDoNotDependOnTheProvider(): void
    {
        $this->only('mistral-large', 'stop');
        $this->menu(dishes: 30);
        $version = $this->version();

        $this->translate(categories: false);

        self::assertCount(30, $this->storedDishes());
        self::assertSame(array_fill(0, 4, 'api.mistral.ai'), $this->hosts, '2 batches × (translate + check)');
        self::assertSame($version + 2, $this->version());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Only $provider configured; every one of its calls answers with $finish. */
    private function only(string $provider, string|int $finish): void
    {
        [$env, $host] = self::PROVIDERS[$provider];
        $_ENV[$env] = 'test-key-never-sent';
        $this->answers = [$host => $finish];
    }

    private function translate(bool $categories = true): void
    {
        $http = new MockHttpClient(fn (string $method, string $url, array $options) => $this->answer($url, $options));
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));
        $router = new AIModelRouter($factory, new NullLogger());
        $container = static::getContainer();

        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurantId);
        (new AiProductTranslator(
            new RouterProductTranslator($router, $factory, new NullLogger()),
            $this->em,
            $container->get(ProductRepository::class),
            $container->get(ProductTranslationRepository::class),
            new NullLogger(),
        ))->translateMissing($restaurant, 'de');
        if ($categories) {
            (new AiCategoryTranslator(
                new RouterCategoryTranslator(new RouterLabelTranslator($router, $factory, new NullLogger())),
                $this->em,
                $container->get(CategoryTranslationRepository::class),
                new NullLogger(),
            ))->translateMissing($restaurant, 'de');
        }
    }

    /**
     * Answers like the provider behind $url would: the request's items
     * translated ("[de] " + name; a check pass returns its candidate
     * unchanged), with the scripted finish reason — or an HTTP error.
     */
    private function answer(string $url, array $options): MockResponse
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $this->hosts[] = $host;
        $script = $this->answers[$host] ?? 500;
        $finish = is_array($script) ? (array_shift($this->answers[$host]) ?? 500) : $script;
        if (is_int($finish)) {
            return new MockResponse('{"error":{"message":"scripted failure"}}', ['http_code' => $finish]);
        }

        $body = json_decode((string) $options['body'], true);
        $gemini = $host === 'generativelanguage.googleapis.com';
        $user = $gemini ? $body['contents'][0]['parts'][0]['text'] : end($body['messages'])['content'];
        [$label, $json] = explode(': ', $user, 2);
        $result = [];
        foreach (json_decode($json, true) as $item) {
            $name = $item['translated_name'] ?? ('[de] ' . ($item['name'] ?? $item['source_name']));
            $result[$item['id']] = $label === 'Dishes' ? ['name' => $name, 'description' => ''] : $name;
        }
        $content = json_encode($result, JSON_UNESCAPED_UNICODE);

        if ($gemini) {
            $parts = $finish === 'parts'
                ? [['text' => mb_substr($content, 0, 7)], ['text' => mb_substr($content, 7)]]
                : [['text' => $content]];

            return new MockResponse(json_encode(['candidates' => [[
                'content'      => ['parts' => $parts, 'role' => 'model'],
                'finishReason' => $finish === 'parts' ? 'STOP' : $finish,
            ]]]));
        }

        return new MockResponse(json_encode(['choices' => [[
            'message'       => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => $finish,
        ]]]));
    }

    /** Spanish menu: category "Postres" with $dishes dishes ("Flan", "Plato 2"…). */
    private function menu(int $dishes): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa proveedores');
        $restaurant->setSlug('translation-providers-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setAdminLocale('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);
        $ct = new CategoryTranslation();
        $ct->setLocale('es');
        $ct->setName('Postres');
        $category->addTranslation($ct);
        $this->em->persist($ct);

        for ($i = 1; $i <= $dishes; $i++) {
            $product = new Product();
            $product->setActive(true);
            $product->setBasePrice(900);
            $product->setPosition($i);
            $category->addProduct($product);
            $this->em->persist($product);
            $t = new ProductTranslation();
            $t->setLocale('es');
            $t->setName($i === 1 ? 'Flan' : "Plato {$i}");
            $t->setSource(ProductTranslation::SOURCE_HUMAN);
            $product->addTranslation($t);
            $this->em->persist($t);
        }
        $this->em->flush();
        $this->em->clear();
    }

    /** @return list<string> */
    private function storedDishes(): array
    {
        return $this->conn->fetchFirstColumn(
            "SELECT t.name FROM product_translation t JOIN product p ON p.id = t.product_id JOIN category c ON c.id = p.category_id
             WHERE c.restaurant_id = ? AND t.locale = 'de' AND t.source = 'ai' ORDER BY p.position",
            [$this->restaurantId],
        );
    }

    /** @return list<string> */
    private function storedCategories(): array
    {
        return $this->conn->fetchFirstColumn(
            "SELECT t.name FROM category_translation t JOIN category c ON c.id = t.category_id WHERE c.restaurant_id = ? AND t.locale = 'de' AND t.source = 'ai'",
            [$this->restaurantId],
        );
    }

    private function version(): int
    {
        return (int) $this->conn->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$this->restaurantId]);
    }
}
