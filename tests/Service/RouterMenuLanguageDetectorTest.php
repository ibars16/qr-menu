<?php

namespace App\Tests\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\RouterMenuLanguageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * RouterMenuLanguageDetector: groq-llama (gpt-oss, a reasoning model) first;
 * an answer cut off at maxTokens (finish_reason=length) or that isn't the
 * JSON asked for is a failure — logged with its reason, never the texts —
 * and Gemini (through AIModelRouter) is asked instead.
 *
 * Real factory/router against a MockHttpClient answering by host: Groq and
 * Gemini as scripted, any other provider a 500 (so the router moves on) —
 * no request can reach a real API.
 */
final class RouterMenuLanguageDetectorTest extends TestCase
{
    private const KEYS = ['GROQ_API_KEY', 'GEMINI_API_KEY'];

    private array $previous = [];
    /** @var list<array{host: string, body: array}> */
    private array $requests = [];
    private object $logger;

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->previous[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = 'test-key-never-sent';
        }
        $this->logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            if ($this->previous[$key] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $this->previous[$key];
            }
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $groq    [content, finish_reason] per Groq request
     * @param list<string|array{0: string, 1: string}> $gemini  content per Gemini request, or [content, finishReason]
     */
    private function detector(array $groq, array $gemini = []): RouterMenuLanguageDetector
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$groq, &$gemini): MockResponse {
            $host = parse_url($url, PHP_URL_HOST);
            $this->requests[] = ['host' => $host, 'body' => json_decode($options['body'] ?? '{}', true) ?? []];

            if ($host === 'api.groq.com' && $groq !== []) {
                [$content, $finish] = array_shift($groq);

                return new MockResponse(json_encode(['choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]], 'usage' => ['completion_tokens' => 30]]));
            }
            if ($host === 'generativelanguage.googleapis.com' && $gemini !== []) {
                $reply = array_shift($gemini);
                [$content, $finish] = is_array($reply) ? $reply : [$reply, 'STOP'];

                return new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => $content]]], 'finishReason' => $finish]]]));
            }

            return new MockResponse('{"error":{"message":"not scripted"}}', ['http_code' => 500]);
        });
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));

        return new RouterMenuLanguageDetector(new AIModelRouter($factory, new NullLogger()), $factory, $this->logger);
    }

    private function hosts(): array
    {
        return array_values(array_unique(array_column($this->requests, 'host')));
    }

    /** @return list<array> the "unusable answer" warnings' context */
    private function unusable(): array
    {
        return array_values(array_map(
            static fn (array $r) => $r[2],
            array_filter($this->logger->records, static fn (array $r) => $r[1] === 'Menu language detection: unusable answer.'),
        ));
    }

    public function testCutOffDetectionFallsBackToGemini(): void
    {
        // What Groq answered on David Test: 30 tokens, 18 of them reasoning.
        $detector = $this->detector([['{"language": "', 'length']], ['{"language": "es"}']);

        self::assertSame('es', $detector->detect(['Carpaccio de gamba roja con foie', 'Asados y guisos']));

        self::assertSame(['api.groq.com', 'generativelanguage.googleapis.com'], array_values(array_intersect($this->hosts(), ['api.groq.com', 'generativelanguage.googleapis.com'])));
        $groq = $this->requests[0]['body'];
        self::assertSame(300, $groq['max_tokens']);
        self::assertSame('low', $groq['reasoning_effort']);

        $warnings = $this->unusable();
        self::assertCount(1, $warnings);
        self::assertSame(['call' => 'detect', 'provider' => 'groq-llama', 'reason' => 'cut off (finish_reason=length)', 'completionTokens' => 30], $warnings[0]);
        self::assertStringNotContainsString('Carpaccio', json_encode($this->logger->records, JSON_UNESCAPED_UNICODE), 'no texts in the log');
    }

    /** How an OpenAI-compatible provider marks an interrupted answer, other than "length". */
    public static function otherStops(): iterable
    {
        foreach (['content_filter', 'error', 'model_length', 'insufficient_system_resource'] as $reason) {
            yield $reason => [$reason];
        }
    }

    /** Valid JSON on purpose: the mark alone makes it a failure, and Gemini is asked. */
    #[DataProvider('otherStops')]
    public function testAnyStopOtherThanStopFallsBackToGemini(string $reason): void
    {
        $detector = $this->detector([['{"language": "fr"}', $reason]], ['{"language": "es"}']);

        self::assertSame('es', $detector->detect(['Carpaccio de gamba roja con foie', 'Asados y guisos']));
        self::assertSame("cut off (finish_reason={$reason})", $this->unusable()[0]['reason']);
    }

    #[DataProvider('otherStops')]
    public function testAnyStopOtherThanStopInTheCheckFallsBackToGemini(string $reason): void
    {
        $texts = ['p:5872' => 'Carpaccio de gamba roja con foie', 'c:635' => 'Carpaccios'];
        $detector = $this->detector([['{"p:5872": "fr", "c:635": "fr"}', $reason]], ['{"p:5872": "es", "c:635": "unsure"}']);

        self::assertSame(['p:5872' => 'es', 'c:635' => null], $detector->detectEach($texts));
    }

    /** Gemini's own marks (after Groq failed): a failure too — no answer rather than a doubtful one. */
    public function testAGeminiAnswerThatDidNotEndNormallyIsNotUsed(): void
    {
        foreach (['MAX_TOKENS', 'SAFETY', 'RECITATION', 'OTHER'] as $reason) {
            $this->requests = [];
            $detector = $this->detector([['{"language": "', 'length']], [['{"language": "fr"}', $reason]]);

            self::assertNull($detector->detect(['Carpaccio de gamba roja con foie', 'Asados y guisos']), $reason);
        }
    }

    public function testAnAnswerThatEndedNormallyIsUsedWithoutGemini(): void
    {
        $detector = $this->detector([['{"language": "fr"}', 'stop']], ['{"language": "es"}']);

        self::assertSame('fr', $detector->detect(['Soupe gratinée', 'Entrées']));
        self::assertSame(['api.groq.com'], $this->hosts());
    }

    public function testCutOffCheckFallsBackToGemini(): void
    {
        $texts = ['p:5872' => 'Carpaccio de gamba roja con foie', 'p:5878' => 'Canelones Casa Cervelló', 'c:635' => 'Carpaccios'];
        $detector = $this->detector(
            [["{\n  \"p:5872\": \"es\",\n  \"p:58", 'length']],
            ['{"p:5872": "es", "p:5878": "es", "c:635": "unsure"}'],
        );

        self::assertSame(['p:5872' => 'es', 'p:5878' => 'es', 'c:635' => null], $detector->detectEach($texts));
        self::assertSame(300 + 20 * 3, $this->requests[0]['body']['max_tokens']);
        self::assertSame('detectEach', $this->unusable()[0]['call']);
    }

    public function testBrokenJsonWithoutCutOffAlsoFallsBack(): void
    {
        $detector = $this->detector([['Spanish, I think.', 'stop']], ['{"p:1": "es"}']);

        self::assertSame(['p:1' => 'es'], $detector->detectEach(['p:1' => 'Paella']));
        self::assertSame('not valid JSON', $this->unusable()[0]['reason']);
    }

    public function testAnswerForOtherKeysIsNotAnAnswer(): void
    {
        $detector = $this->detector([['{"language": "es"}', 'stop']], ['{"p:1": "en"}']);

        self::assertSame(['p:1' => 'en'], $detector->detectEach(['p:1' => 'Red wine']));
        self::assertSame('JSON not in the expected shape', $this->unusable()[0]['reason']);
    }

    public function testGoodGroqAnswerNeedsNoFallback(): void
    {
        $detector = $this->detector([['{"p:1": "es", "p:2": "unsure"}', 'stop']]);

        self::assertSame(['p:1' => 'es', 'p:2' => null], $detector->detectEach(['p:1' => 'Paella', 'p:2' => 'Pizza margarita']));
        self::assertSame(['api.groq.com'], $this->hosts());
        self::assertSame([], $this->unusable());
    }

    public function testEveryOptionUnusableIsNull(): void
    {
        $detector = $this->detector([['{"language": "', 'length'], ['{"language": "', 'length']], ['{"language": "']);

        self::assertNull($detector->detect(['Paella']));
        self::assertNotEmpty($this->unusable());
    }
}
