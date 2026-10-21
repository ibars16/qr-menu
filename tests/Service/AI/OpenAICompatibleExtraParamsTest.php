<?php

namespace App\Tests\Service\AI;

use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An openai_compatible provider's `extra_params` (config/ai_providers.yaml)
 * reach that provider's request body only: groq-llama sends
 * reasoning_effort, openai-mini — same adapter — doesn't.
 *
 * Reads the real config/ai_providers.yaml through the real factory, against
 * a MockHttpClient that records each request body — no request can reach a
 * real API.
 */
final class OpenAICompatibleExtraParamsTest extends TestCase
{
    private const KEYS = ['GROQ_API_KEY', 'OPENAI_API_KEY'];

    /** @var array<string, mixed> */
    private array $previousKeys = [];

    /** @var array<string, array<string, mixed>> request host => decoded JSON body */
    private array $bodies = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->previousKeys[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = 'test-key-never-sent';
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousKeys as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function complete(string $providerId): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->bodies[parse_url($url, PHP_URL_HOST)] = json_decode($options['body'], true);

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => 'ok']]]]));
        });
        $provider = (new AIProviderFactory($http, dirname(__DIR__, 3)))->getProviderById($providerId);
        self::assertNotNull($provider, "{$providerId} must be configured");

        $provider->complete(new AIRequest('system', [new ChatMessage('user', 'hola')], maxTokens: 600, temperature: 0.1));
    }

    public function testGroqSendsReasoningEffortLow(): void
    {
        $this->complete('groq-llama');

        $body = $this->bodies['api.groq.com'];
        self::assertSame('low', $body['reasoning_effort']);
        self::assertSame('openai/gpt-oss-120b', $body['model']);
        self::assertSame(600, $body['max_tokens']);
    }

    public function testAnotherProviderOfTheSameAdapterDoesNotSendIt(): void
    {
        $this->complete('openai-mini');

        $body = $this->bodies['api.openai.com'];
        self::assertArrayNotHasKey('reasoning_effort', $body);
        self::assertSame(['model', 'messages', 'max_tokens', 'temperature'], array_keys($body));
    }
}
