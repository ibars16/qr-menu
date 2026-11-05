<?php

namespace App\Tests\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\RouterCategoryTranslator;
use App\Service\RouterLabelTranslator;
use App\Service\RouterProductTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use App\Service\TranslationAiCaller;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The translators' prompts (TranslationGuidelines + the dish's menu section)
 * and withIndependentReview(): the review pass by another AI than the one
 * that translated — Gemini when Groq translated, Groq when Gemini did (Groq
 * out of tokens) — the translation kept as it is when no other answers.
 * Without it, the review stays with the usual chain (Groq first).
 *
 * Real factory/router against a MockHttpClient answering by host as
 * scripted (any other provider: a 500) — no request reaches a real API.
 */
final class IndependentTranslationReviewTest extends TestCase
{
    private const KEYS = ['GROQ_API_KEY', 'GEMINI_API_KEY'];
    private const DISHES = [
        5885 => ['name' => 'Ternasco de cordero', 'description' => null, 'section' => 'Asados y guisos'],
    ];

    private array $previous = [];
    /** @var list<array{host: string, prompt: string, user: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->previous[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = 'test-key-never-sent';
        }
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
     * @param list<string|int> $groq    replies per Groq request: content, or an HTTP error code
     * @param list<string|int> $gemini  same, per Gemini request
     */
    private function factory(array $groq, array $gemini): AIProviderFactory
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$groq, &$gemini): MockResponse {
            $host = parse_url($url, PHP_URL_HOST);
            $body = json_decode($options['body'] ?? '{}', true) ?? [];
            $this->requests[] = ($host === 'api.groq.com'
                ? ['host' => $host, 'prompt' => $body['messages'][0]['content'] ?? '', 'user' => $body['messages'][1]['content'] ?? '']
                : ['host' => $host, 'prompt' => $body['systemInstruction']['parts'][0]['text'] ?? '', 'user' => $body['contents'][0]['parts'][0]['text'] ?? ''])
                + ['maxDuration' => (float) ($options['max_duration'] ?? 0)];

            $reply = match ($host) {
                'api.groq.com'                      => array_shift($groq),
                'generativelanguage.googleapis.com' => array_shift($gemini),
                default                             => 500,
            };
            if ($reply === 'slow') {
                // What HttpClient throws once max_duration is reached.
                throw new TransportException(sprintf('Max duration was reached for "%s".', $url));
            }
            if ($reply === null || is_int($reply)) {
                return new MockResponse('{"error":{"message":"scripted failure"}}', ['http_code' => $reply ?? 500]);
            }

            return $host === 'api.groq.com'
                ? new MockResponse(json_encode(['choices' => [['message' => ['content' => $reply], 'finish_reason' => 'stop']]]))
                : new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => $reply]]], 'finishReason' => 'STOP']]]));
        });

        return new AIProviderFactory($http, dirname(__DIR__, 2));
    }

    private function dishTranslator(array $groq, array $gemini): RouterProductTranslator
    {
        $factory = $this->factory($groq, $gemini);

        return new RouterProductTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger());
    }

    private function categoryTranslator(array $groq, array $gemini): RouterCategoryTranslator
    {
        $factory = $this->factory($groq, $gemini);

        return new RouterCategoryTranslator(new RouterLabelTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger()));
    }

    private static function dish(string $name): string
    {
        return json_encode(['5885' => ['name' => $name, 'description' => '']]);
    }

    private function hosts(): array
    {
        return array_column($this->requests, 'host');
    }

    // ── Prompts ──

    public function testPromptsCarryTheGuidelinesAndTheMenuSection(): void
    {
        $this->dishTranslator([self::dish('Lamb tenderloin'), self::dish('Ternasco (young lamb)')], [])
            ->translate(self::DISHES, 'en', [5885 => 'es']);

        [$translate, $review] = $this->requests;
        self::assertStringContainsString('false friends', $translate['prompt']);
        self::assertStringContainsString('Never add or change details', $translate['prompt']);
        self::assertStringContainsString('keep its original name and add a short plain explanation', $translate['prompt']);
        self::assertStringContainsString('"menu_section":"Asados y guisos"', $translate['user']);
        self::assertStringContainsString('check the MEANING', $review['prompt']);
        self::assertStringContainsString('"menu_section":"Asados y guisos"', $review['user']);
    }

    public function testWithoutIndependentReviewTheSameChainReviews(): void
    {
        $result = $this->dishTranslator([self::dish('Lamb tenderloin'), self::dish('Ternasco (young lamb)')], [])
            ->translate(self::DISHES, 'en', [5885 => 'es']);

        self::assertSame(['api.groq.com', 'api.groq.com'], $this->hosts());
        self::assertSame('Ternasco (young lamb)', $result[5885]['name']);
    }

    // ── Independent review (the main-language change) ──

    public function testGroqTranslatesGeminiReviews(): void
    {
        $result = $this->dishTranslator([self::dish('Lamb tenderloin')], [self::dish('Ternasco (young lamb)')])
            ->withIndependentReview()
            ->translate(self::DISHES, 'en', [5885 => 'es']);

        self::assertSame(['api.groq.com', 'generativelanguage.googleapis.com'], $this->hosts());
        self::assertStringContainsString('check the MEANING', $this->requests[1]['prompt']);
        self::assertSame('Ternasco (young lamb)', $result[5885]['name']);
    }

    public function testGroqOutOfTokensGeminiTranslatesAndGroqReviews(): void
    {
        // 429 on the translation: the usual jump to Gemini; the review then goes back to Groq.
        $result = $this->dishTranslator([429, self::dish('Ternasco (young lamb)')], [self::dish('Lamb tenderloin')])
            ->withIndependentReview()
            ->translate(self::DISHES, 'en', [5885 => 'es']);

        self::assertSame(['api.groq.com', 'generativelanguage.googleapis.com', 'api.groq.com'], $this->hosts());
        self::assertSame('Ternasco (young lamb)', $result[5885]['name']);
    }

    public function testNoOtherAiAnswersKeepsTheTranslation(): void
    {
        $result = $this->dishTranslator([self::dish('Roast lamb')], [503])
            ->withIndependentReview()
            ->translate(self::DISHES, 'en', [5885 => 'es']);

        self::assertSame(['api.groq.com', 'generativelanguage.googleapis.com'], $this->hosts(), 'never reviewed by Groq itself');
        self::assertSame('Roast lamb', $result[5885]['name']);
    }

    public function testTheReviewWaitsAtMostTenSeconds(): void
    {
        $this->dishTranslator([self::dish('Roast lamb')], [self::dish('Ternasco (young lamb)')])
            ->withIndependentReview()
            ->translate(self::DISHES, 'en', [5885 => 'es']);

        self::assertSame(0.0, $this->requests[0]['maxDuration'], 'the translation itself: no new limit');
        self::assertSame(10.0, TranslationAiCaller::ELSEWHERE_MAX_SECONDS);
        self::assertGreaterThan(9.0, $this->requests[1]['maxDuration']);
        self::assertLessThanOrEqual(10.0, $this->requests[1]['maxDuration']);
    }

    public function testASlowReviewKeepsTheTranslation(): void
    {
        $result = $this->dishTranslator([self::dish('Roast lamb')], ['slow'])
            ->withIndependentReview()
            ->translate(self::DISHES, 'en', [5885 => 'es']);

        self::assertSame(['api.groq.com', 'generativelanguage.googleapis.com'], $this->hosts(), 'never reviewed by Groq itself');
        self::assertSame('Roast lamb', $result[5885]['name']);
    }

    public function testOriginalTranslatorIsUnchanged(): void
    {
        $translator = $this->dishTranslator([self::dish('Roast lamb'), self::dish('Ternasco (young lamb)')], []);
        $translator->withIndependentReview();

        $translator->translate(self::DISHES, 'en', [5885 => 'es']);
        self::assertSame(['api.groq.com', 'api.groq.com'], $this->hosts());
    }

    public function testCategoriesToo(): void
    {
        $result = $this->categoryTranslator([json_encode(['638' => 'Roasts and stews'])], [json_encode(['638' => 'Roasts & stews'])])
            ->withIndependentReview()
            ->translate([638 => 'Asados y guisos'], 'en', [638 => 'es']);

        self::assertSame(['api.groq.com', 'generativelanguage.googleapis.com'], $this->hosts());
        self::assertStringContainsString('false friends', $this->requests[0]['prompt']);
        self::assertStringContainsString('check the MEANING', $this->requests[1]['prompt']);
        self::assertSame([638 => 'Roasts & stews'], $result);
    }
}
