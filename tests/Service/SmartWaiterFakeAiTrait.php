<?php

namespace App\Tests\Service;

use App\Entity\Restaurant;
use App\Entity\SmartWaiterExchangeLog;
use App\Repository\AllergenRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\OwnerTextTranslationRepository;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\HeuristicChatComplexityClassifier;
use App\Service\CurrencyConverter;
use App\Service\MenuContextBuilder;
use App\Service\OwnerTextTranslationService;
use App\Service\ProductAllergenResolver;
use App\Service\ProductTranslatorInterface;
use App\Service\SmartWaiterConversationStore;
use App\Service\SmartWaiterDishSafety;
use App\Service\SmartWaiterFailureAlertMailer;
use App\Service\SmartWaiterPromptBuilder;
use App\Service\SmartWaiterRequestBuilder;
use App\Service\SmartWaiterService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The real Smart Waiter pipeline (SmartWaiterService, AIModelRouter,
 * AIProviderFactory, MenuContextBuilder, prompt, conversation store) against
 * a FAKE AI: a MockHttpClient answering each provider in its own real
 * response shape — Gemini's candidates/finishReason, Groq's and OpenAI's
 * choices/finish_reason. Only gemini-flash-lite, groq-llama and openai-mini
 * get a fake key (every other provider's is blanked for the test); an
 * unscripted request gets a 500, and no request can reach a real API.
 *
 * Order with those three: simple = Gemini → Groq → OpenAI; complex =
 * OpenAI → Gemini → Groq (AIModelRouter, config/ai_providers.yaml).
 *
 * Replies are scripted per host; for Gemini, "host/model" (e.g.
 * self::GEMINI . '/gemini-flash-latest') scripts one model only, taking
 * precedence over the plain host — two Gemini models share one host.
 * Every request records its host, model and timeout too.
 *
 * The using TestCase must call fakeAiSetUp() / fakeAiTearDown().
 */
trait SmartWaiterFakeAiTrait
{
    private const GEMINI = 'generativelanguage.googleapis.com';
    private const GROQ = 'api.groq.com';
    private const OPENAI = 'api.openai.com';

    private const KEYS = [
        'GEMINI_API_KEY' => 'test-key-never-sent',
        'GROQ_API_KEY' => 'test-key-never-sent',
        'OPENAI_API_KEY' => 'test-key-never-sent',
        'OPENROUTER_API_KEY' => '',
        'MISTRAL_API_KEY' => '',
        'DEEPSEEK_API_KEY' => '',
    ];

    private const COMPLEX_MESSAGE = 'Somos cuatro personas, uno de nosotros no come carne y otro no puede tomar nada picante, ¿qué platos nos recomiendas compartir para cenar esta noche sin gastar mucho dinero?';

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    /** @var array<string, list<array{0: string, 1: ?string}>> host => scripted [text, finish reason] replies */
    private array $replies = [];

    /** @var list<array{host: string, model: string, timeout: ?float, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var SmartWaiterExchangeLog[] */
    private array $logs = [];

    private SmartWaiterConversationStore $store;

    private function fakeAiSetUp(): void
    {
        foreach (self::KEYS as $name => $value) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
            $_ENV[$name] = $value;
        }
        $this->store = new SmartWaiterConversationStore(new ArrayAdapter(), new NullLogger());
    }

    private function fakeAiTearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    private function factory(): AIProviderFactory
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $body = json_decode($options['body'] ?? '{}', true) ?? [];
            $model = $host === self::GEMINI
                ? (preg_match('#/models/([^:/]+):generateContent#', $url, $m) === 1 ? $m[1] : '')
                : (string) ($body['model'] ?? '');
            $this->requests[] = ['host' => $host, 'model' => $model, 'timeout' => isset($options['timeout']) ? (float) $options['timeout'] : null, 'body' => $body];
            $key = isset($this->replies[$host . '/' . $model]) ? $host . '/' . $model : $host;
            $reply = isset($this->replies[$key]) ? array_shift($this->replies[$key]) : null;
            if ($reply === null) {
                return new MockResponse('{"error":{"message":"unexpected request"}}', ['http_code' => 500]);
            }
            [$text, $finish] = $reply;

            if ($host === self::GEMINI) {
                return new MockResponse(json_encode([
                    'candidates' => [['content' => ['parts' => [['text' => $text]], 'role' => 'model'], 'finishReason' => $finish]],
                    'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20],
                ]));
            }

            return new MockResponse(json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => $finish]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
            ]));
        });

        return new AIProviderFactory($http, dirname(__DIR__, 2));
    }

    private function restaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Test');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        (new \ReflectionProperty(Restaurant::class, 'id'))->setValue($restaurant, 4242);

        return $restaurant;
    }

    /** The real request builder — what both the chat and the model comparison use — over an empty menu. */
    private function requestBuilder(): SmartWaiterRequestBuilder
    {
        $menuContextBuilder = new MenuContextBuilder(
            new ProductAllergenResolver(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(AllergenRepository::class),
                $this->createStub(CacheItemPoolInterface::class),
            ),
            new CurrencyConverter($this->createStub(ExchangeRateRepository::class)),
            new OwnerTextTranslationService(
                $this->createStub(OwnerTextTranslationRepository::class),
                $this->createStub(ProductTranslatorInterface::class),
                new NullLogger(),
                $this->createStub(EntityManagerInterface::class),
            ),
            new SmartWaiterDishSafety(),
            $this->createStub(AllergenRepository::class),
        );

        return new SmartWaiterRequestBuilder($menuContextBuilder, new SmartWaiterPromptBuilder(), new HeuristicChatComplexityClassifier());
    }

    private function service(): SmartWaiterService
    {
        $factory = $this->factory();

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof SmartWaiterExchangeLog) {
                $this->logs[] = $entity;
            }
        });

        return new SmartWaiterService(
            $this->requestBuilder(),
            new AIModelRouter($factory, new NullLogger()),
            $factory,
            $this->store,
            // No from address: the failure alert sends nothing.
            new SmartWaiterFailureAlertMailer($this->createStub(TransportInterface::class), new NullLogger(), '', ''),
            $em,
        );
    }

    /** @return list<string> */
    private function hosts(): array
    {
        return array_column($this->requests, 'host');
    }

    /** @return list<string> every message text of one request, in order (system prompt excluded) */
    private function sentMessages(array $request): array
    {
        if ($request['host'] === self::GEMINI) {
            return array_map(static fn (array $c) => $c['parts'][0]['text'], $request['body']['contents']);
        }

        return array_values(array_map(
            static fn (array $m) => $m['content'],
            array_filter($request['body']['messages'], static fn (array $m) => $m['role'] !== 'system'),
        ));
    }

    /** @return list<array{0: string, 1: string}> every [role, text] turn of one request, in order (system prompt excluded); roles as that provider names them */
    private function sentTurns(array $request): array
    {
        if ($request['host'] === self::GEMINI) {
            return array_map(static fn (array $c) => [$c['role'], $c['parts'][0]['text']], $request['body']['contents']);
        }

        return array_values(array_map(
            static fn (array $m) => [$m['role'], $m['content']],
            array_filter($request['body']['messages'], static fn (array $m) => $m['role'] !== 'system'),
        ));
    }

    /** The instruction exactly as one request carried it: Gemini's systemInstruction, or the OpenAI-shape system message. */
    private function systemText(array $request): string
    {
        if ($request['host'] === self::GEMINI) {
            return $request['body']['systemInstruction']['parts'][0]['text'];
        }

        return $request['body']['messages'][0]['content'];
    }

    /** @return list<string>|null the EARLIER CUSTOMER MESSAGES block of one request, decoded; null when there is none */
    private function earlierCustomerMessages(array $request): ?array
    {
        $system = $this->systemText($request);
        $header = strpos($system, "\nEARLIER CUSTOMER MESSAGES (JSON");
        if ($header === false) {
            return null;
        }
        $jsonStart = strpos($system, "\n", $header + 1) + 1;
        $jsonEnd = strpos($system, "\n\nREMINDERS —", $jsonStart);

        return json_decode(substr($system, $jsonStart, $jsonEnd - $jsonStart), true, flags: JSON_THROW_ON_ERROR);
    }
}
