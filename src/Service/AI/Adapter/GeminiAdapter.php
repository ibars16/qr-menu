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
 * Google Gemini via the generateContent REST endpoint (same endpoint shape
 * already used by GeminiClassifierProvider for the offline classification
 * pipeline — this is the chat/multi-turn sibling of that, not a reuse of it,
 * since a live customer conversation has different latency/failure-handling
 * needs than a batch job).
 */
final class GeminiAdapter implements AIProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $id,
        private readonly string $model,
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
        $contents = [];
        foreach ($request->messages as $message) {
            $contents[] = [
                'role' => $message->role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ];
        }

        $started = microtime(true);

        try {
            $response = $this->httpClient->request('POST', sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                $this->model,
            ), [
                'query' => ['key' => $this->apiKey],
                'headers' => ['Content-Type' => 'application/json'],
                'json' => [
                    'systemInstruction' => ['parts' => [['text' => $request->systemPrompt]]],
                    'contents' => $contents,
                    'generationConfig' => [
                        'maxOutputTokens' => $request->maxTokens,
                        'temperature' => $request->temperature,
                    ],
                ],
                'timeout' => 20,
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(throw: false);
        } catch (TimeoutExceptionInterface $e) {
            throw new AIProviderException($this->id, AIFailureReason::TIMEOUT, 'Gemini request timed out', $e);
        } catch (HttpExceptionInterface $e) {
            throw new AIProviderException($this->id, AIFailureReason::NETWORK_ERROR, 'Gemini network error: ' . $e->getMessage(), $e);
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($status === 429) {
            throw new AIProviderException($this->id, AIFailureReason::RATE_LIMITED, 'Gemini rate limited / quota exceeded');
        }
        if ($status >= 400) {
            $msg = $body['error']['message'] ?? ('HTTP ' . $status);
            throw new AIProviderException($this->id, AIFailureReason::HTTP_ERROR, 'Gemini error: ' . $msg);
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new AIProviderException($this->id, AIFailureReason::INVALID_RESPONSE, 'Gemini returned no usable text');
        }

        $usage = $body['usageMetadata'] ?? [];

        return new AIResponse(
            content: trim($text),
            providerId: $this->id,
            model: $this->model,
            latencyMs: $latencyMs,
            promptTokens: isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
            completionTokens: isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
        );
    }
}
