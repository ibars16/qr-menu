<?php

namespace App\Service\AI\Adapter;

use App\Service\AI\AIFailureReason;
use App\Service\AI\AIProviderException;
use App\Service\AI\MenuVisionAnalyzer;
use App\Service\AI\MenuVisionResult;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Gemini's generateContent endpoint used for image input rather than
 * text — same host, same model, same error-handling shape as GeminiAdapter
 * (the chat adapter), copied deliberately rather than shared: this is a
 * sibling, not an extension. The only real differences are the request body
 * (an inlineData image part instead of text-only content) and that the
 * response text is itself expected to be JSON, which this class decodes
 * before handing it back — GeminiAdapter never does that, it returns text
 * as-is.
 */
final class GeminiVisionAdapter implements MenuVisionAnalyzer
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

    public function analyzeMenuPage(string $imageBytes, string $mimeType, string $instructions): MenuVisionResult
    {
        $started = microtime(true);

        try {
            $response = $this->httpClient->request('POST', sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                $this->model,
            ), [
                'query' => ['key' => $this->apiKey],
                'headers' => ['Content-Type' => 'application/json'],
                'json' => [
                    'systemInstruction' => ['parts' => [['text' => $instructions]]],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'maxOutputTokens' => 8192,
                        'temperature' => 0.1,
                    ],
                ],
                'timeout' => 60,
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(throw: false);
        } catch (TimeoutExceptionInterface $e) {
            throw new AIProviderException($this->id, AIFailureReason::TIMEOUT, 'Gemini vision request timed out', $e);
        } catch (HttpExceptionInterface $e) {
            throw new AIProviderException($this->id, AIFailureReason::NETWORK_ERROR, 'Gemini vision network error: ' . $e->getMessage(), $e);
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($status === 429) {
            throw new AIProviderException($this->id, AIFailureReason::RATE_LIMITED, 'Gemini vision rate limited / quota exceeded');
        }
        if ($status >= 400) {
            $msg = $body['error']['message'] ?? ('HTTP ' . $status);
            throw new AIProviderException($this->id, AIFailureReason::HTTP_ERROR, 'Gemini vision error: ' . $msg);
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new AIProviderException($this->id, AIFailureReason::INVALID_RESPONSE, 'Gemini vision returned no usable text');
        }

        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded) || !isset($decoded['categories']) || !is_array($decoded['categories'])) {
            throw new AIProviderException($this->id, AIFailureReason::INVALID_RESPONSE, 'Gemini vision returned text that was not the expected JSON shape');
        }

        $usage = $body['usageMetadata'] ?? [];

        return new MenuVisionResult(
            data: $decoded,
            providerId: $this->id,
            model: $this->model,
            latencyMs: $latencyMs,
            promptTokens: isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
            completionTokens: isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
        );
    }
}
