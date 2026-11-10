<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\SmartWaiterExchangeLog;
use App\Service\AI\AIProviderFactory;
use App\Service\SmartWaiterNeedsDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The whole chat endpoint (POST /r/{slug}/chat) for a menu that has a
 * fixed-price menu ("menú del día") — which used to break
 * MenuContextBuilder::extractVocabulary() — answered by a fake Gemini, and
 * what actually reaches the AI: the new rules and the new dish shape.
 *
 * AIProviderFactory is replaced by one on a MockHttpClient with only
 * gemini-flash-lite keyed (fake key, every other provider's blanked): no
 * request can reach a real API. Uses the test database only (see
 * doctrine.yaml's dbname_suffix).
 */
final class SmartWaiterChatTest extends WebTestCase
{
    private const KEYS = [
        'GEMINI_API_KEY' => 'test-key-never-sent',
        'GROQ_API_KEY' => '',
        'OPENAI_API_KEY' => '',
        'OPENROUTER_API_KEY' => '',
        'MISTRAL_API_KEY' => '',
        'DEEPSEEK_API_KEY' => '',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    /** @var list<array{host: string, url: string, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<array<string, mixed>> the needs detection calls (request bodies) */
    private array $detections = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $name => $value) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
            $_ENV[$name] = $value;
        }

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $body = json_decode($options['body'] ?? '{}', true) ?? [];
            if ($host !== 'generativelanguage.googleapis.com') {
                $this->requests[] = ['host' => $host, 'url' => $url, 'body' => $body];

                return new MockResponse('{"error":{"message":"unexpected request"}}', ['http_code' => 500]);
            }
            // The needs detection (small call, no menu): kept apart, answered "nothing to avoid".
            if (str_starts_with($body['systemInstruction']['parts'][0]['text'] ?? '', SmartWaiterNeedsDetector::PROMPT_START)) {
                $this->detections[] = $body;

                return new MockResponse(json_encode([
                    'candidates' => [['content' => ['parts' => [['text' => '{"avoid":[],"diets":[],"allergy":false,"other":false}']], 'role' => 'model'], 'finishReason' => 'STOP']],
                ]));
            }
            $this->requests[] = ['host' => $host, 'url' => $url, 'body' => $body];

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'De primero hay sopa.']], 'role' => 'model'], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 8],
            ]));
        });
        static::getContainer()->set(AIProviderFactory::class, new AIProviderFactory($http, static::getContainer()->getParameter('kernel.project_dir')));

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Smart Waiter Chat Test');
        $this->restaurant->setSlug('sw-chat-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->em->persist($this->restaurant);

        $carta = $this->category('Entrantes');
        $this->dish($carta, 'Carpaccio de salmón', 1400);

        $menu = $this->category('Menú del día', menuPriceCents: 1500);
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $section->setPosition(0);
        $menu->addMenuSection($section);
        $this->em->persist($section);
        $this->dish($menu, 'Sopa de la casa', 0, $section);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurant->getId());
        if ($restaurant) {
            foreach ($this->em->getRepository(SmartWaiterExchangeLog::class)->findBy(['restaurant' => $restaurant]) as $log) {
                $this->em->remove($log);
            }
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

        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        parent::tearDown();
    }

    private function category(string $name, ?int $menuPriceCents = null): Category
    {
        $category = new Category();
        $category->setActive(true);
        if ($menuPriceCents !== null) {
            $category->setMenuPrice($menuPriceCents);
        }
        $this->restaurant->addCategory($category);
        $this->em->persist($category);

        $t = new CategoryTranslation();
        $t->setLocale('es');
        $t->setName($name);
        $category->addTranslation($t);
        $this->em->persist($t);

        return $category;
    }

    private function dish(Category $category, string $name, int $priceCents, ?MenuSection $section = null): void
    {
        $product = new Product();
        $product->setBasePrice($priceCents);
        $product->setActive(true);
        $category->addProduct($product);
        $section?->addProduct($product);
        $this->em->persist($product);

        $t = new ProductTranslation();
        $t->setLocale('es');
        $t->setName($name);
        $product->addTranslation($t);
        $this->em->persist($t);
    }

    private function ask(string $message): array
    {
        $this->client->request(
            'POST',
            '/r/' . $this->restaurant->getSlug() . '/chat',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['message' => $message, 'locale' => 'es']),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Pinning a model is only for app:smart-waiter:ask --modelos (console): nothing a customer sends can pick one, not even a manual_only one. */
    public function testNothingInTheRequestCanPickTheModel(): void
    {
        $this->client->request(
            'POST',
            '/r/' . $this->restaurant->getSlug() . '/chat',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['message' => '¿Qué entrantes hay?', 'locale' => 'es', 'provider' => 'gemini-flash', 'modelos' => 'gemini-flash', 'model' => 'gemini-flash-latest']),
        );

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->requests);
        self::assertStringContainsString('/models/gemini-flash-lite-latest:generateContent', $this->requests[0]['url'], 'the usual order: Gemini flash-lite first');
    }

    public function testAMenuWithASetMenuIsAnswered(): void
    {
        $data = $this->ask('¿Qué primeros hay en el menú del día?');

        self::assertResponseIsSuccessful();
        self::assertFalse($data['error']);
        self::assertSame('De primero hay sopa.', $data['reply']);
        self::assertSame(['generativelanguage.googleapis.com'], array_column($this->requests, 'host'));
    }

    public function testTheAiReceivesTheNewRulesAndTheNewDishShape(): void
    {
        $this->ask('¿El carpaccio lleva pan? Soy alérgico.');

        self::assertCount(1, $this->requests);
        $prompt = $this->requests[0]['body']['systemInstruction']['parts'][0]['text'];

        // This fake detection finds nothing to avoid: no sorting rule, no staff sentence (see SmartWaiterDishFitTest / SmartWaiterNeedsFlowTest).
        foreach (['5. DIETS.', '6. ALLERGENS.', '- STAFF: never advise checking with the staff', '7. NO PROMISES.', '8. STAY THE WAITER.', 'Address the customer the same way'] as $rule) {
            self::assertStringContainsString($rule, $prompt);
        }

        $menuStart = strpos($prompt, "MENU DATA (JSON — the complete and only menu of this restaurant):\n") + strlen("MENU DATA (JSON — the complete and only menu of this restaurant):\n");
        $menuJson = substr($prompt, $menuStart, strpos($prompt, "\n\nREMINDERS —") - $menuStart);
        self::assertStringEndsWith('and greet only in your first reply. In Spanish, unless the customer uses "usted", keep "tú" also when apologising or declining (e.g. "puedo ayudarte", never "ayudarle").', $prompt, 'the reminders close the instruction, after the menu');
        $categories = json_decode(trim($menuJson), true);
        self::assertIsArray($categories, 'the menu data is valid JSON');

        $carpaccio = $categories[0]['products'][0];
        self::assertSame('Carpaccio de salmón', $carpaccio['name']);
        self::assertSame('none', $carpaccio['labels']);
        self::assertSame('?', $carpaccio['allergens']);
        self::assertSame(14.0, (float) $carpaccio['price']);

        $setDish = $categories[1]['sections'][0]['dishes'][0];
        self::assertSame(15.0, (float) $categories[1]['menu_price']);
        self::assertSame('Sopa de la casa', $setDish['name']);
        self::assertSame('none', $setDish['labels']);
        self::assertSame('?', $setDish['allergens']);
        self::assertStringNotContainsString('unknown (', $prompt, 'no quotable phrase for unknown allergens anywhere');
    }
}
