<?php

namespace App\Tests\Service\AI;

use App\Service\AI\Adapter\GeminiAdapter;
use App\Service\AI\Adapter\GeminiVisionAdapter;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;
use App\Service\Classification\GeminiClassifierProvider;
use App\Service\GeminiIngredientTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every Gemini caller sends its key in the x-goog-api-key header, never in
 * the URL — a URL ends up verbatim in HttpClient's request/response log
 * lines (which is how a real key reached var/log/dev.log).
 */
final class GeminiApiKeyHeaderTest extends TestCase
{
    private const KEY = 'test-gemini-key-123';

    /** @return iterable<string, array{\Closure(MockHttpClient): mixed}> */
    public static function callers(): iterable
    {
        yield 'GeminiAdapter (Smart Waiter / router)' => [static fn (MockHttpClient $http) => (new GeminiAdapter($http, 'gemini-flash-lite', 'gemini-flash-lite-latest', self::KEY))
            ->complete(new AIRequest(systemPrompt: 'sys', messages: [new ChatMessage('user', 'hola')], maxTokens: 50, temperature: 0.1))];
        yield 'GeminiVisionAdapter (menu import)' => [static fn (MockHttpClient $http) => (new GeminiVisionAdapter($http, 'gemini-flash-lite', 'gemini-flash-lite-latest', self::KEY))
            ->analyzeMenuPage('fake-image-bytes', 'image/png', 'extract')];
        yield 'GeminiIngredientTranslator' => [static fn (MockHttpClient $http) => (new GeminiIngredientTranslator($http, self::KEY))
            ->translate([1 => 'Tomate'], 'it')];
        yield 'GeminiClassifierProvider' => [static fn (MockHttpClient $http) => (new GeminiClassifierProvider($http, self::KEY))
            ->classify([1 => 'Tomate'], 'classify', ['vegan'])];
    }

    #[DataProvider('callers')]
    public function testTheKeyTravelsInTheHeaderAndNeverInTheUrl(\Closure $call): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['url' => $url, 'headers' => $options['normalized_headers'] ?? []];

            return new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]]));
        });

        try {
            $call($http);
        } catch (\Throwable) {
            // Only the outgoing request matters here, not how '{}' is parsed.
        }

        self::assertCount(1, $requests);
        self::assertStringStartsWith('https://generativelanguage.googleapis.com/', $requests[0]['url']);
        self::assertStringNotContainsString('key=', $requests[0]['url']);
        self::assertStringNotContainsString(self::KEY, $requests[0]['url']);
        self::assertSame(['x-goog-api-key: ' . self::KEY], $requests[0]['headers']['x-goog-api-key'] ?? null);
    }
}
