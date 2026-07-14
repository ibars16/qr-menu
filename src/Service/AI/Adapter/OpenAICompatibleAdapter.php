<?php

namespace App\Service\AI\Adapter;

use App\Service\AI\AIFailureReason;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderInterface;
use App\Service\AI\AIRequest;
use App\Service\AI\AIResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One adapter for every provider that speaks the OpenAI chat-completions
 * shape — OpenAI itself, Groq, OpenRouter, Mistral, DeepSeek, and anything
 * else compatible added later purely via config/ai_providers.yaml
 * (base_url + model + api_key_env). Nothing provider-specific lives in code.
 */
final class OpenAICompatibleAdapter implements AIProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $id,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function complete(AIRequest $request): AIResponse
    {
        $messages = [['role' => 'system', 'content' => $request->systemPrompt]];
        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message->role === 'assistant' ? 'assistant' : 'user', 'content' => $message->content];
        }

        $started = microtime(true);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages,
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                ],
                'timeout' => 20,
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(throw: false);
        } catch (TimeoutExceptionInterface $e) {
            throw new AIProviderException($this->id, AIFailureReason::TIMEOUT, $this->id . ' request timed out', $e);
        } catch (HttpExceptionInterface $e) {
            throw new AIProviderException($this->id, AIFailureReason::NETWORK_ERROR, $this->id . ' network error: ' . $e->getMessage(), $e);
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($status === 429) {
            throw new AIProviderException($this->id, AIFailureReason::RATE_LIMITED, $this->id . ' rate limited / quota exceeded');
        }
        if ($status >= 400) {
            $msg = $body['error']['message'] ?? ('HTTP ' . $status);
            throw new AIProviderException($this->id, AIFailureReason::HTTP_ERROR, $this->id . ' error: ' . $msg);
        }

        $text = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new AIProviderException($this->id, AIFailureReason::INVALID_RESPONSE, $this->id . ' returned no usable text');
        }

        $usage = $body['usage'] ?? [];

        return new AIResponse(
            content: trim($text),
            providerId: $this->id,
            model: $this->model,
            latencyMs: $latencyMs,
            promptTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            completionTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }
}
