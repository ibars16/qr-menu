<?php

namespace App\Service\DishPhoto;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gemini image generation through the Interactions API — Google's current
 * documented way to get an image (generateContent, used by GeminiAdapter
 * for text, is no longer what its image docs describe). 4:3 at 1K is
 * 1200×896, already the size DishPhotoStorage converts to.
 *
 * Confirmed with one real call (2026-11-11, gemini-3.1-flash-lite-image):
 * 200 with steps = [thought (a ~1.2MB signature), model_output → one
 * {type: image, mime_type: image/jpeg, data: base64}], 1200×896, ~7 s;
 * no top-level output_image. Still unseen: how a safety refusal comes back —
 * anything that isn't an HTTP error and holds no image is treated as Refused.
 */
final class GeminiDishImageGenerator implements DishImageGeneratorInterface
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    private const API_REVISION = '2026-05-20';
    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function generate(string $prompt): GeneratedImage
    {
        return $this->request($prompt, '4:3');
    }

    /**
     * Same call with the photo as an inline image part next to the text.
     * Not yet confirmed with a real call (the model accepts input images
     * for editing per Google's docs; the Interactions API takes a list of
     * typed content parts as input).
     */
    public function edit(string $prompt, GeneratedImage $image, string $mimeType, string $aspectRatio): GeneratedImage
    {
        return $this->request([
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'image', 'mime_type' => $mimeType, 'data' => base64_encode($image->bytes)],
        ], $aspectRatio);
    }

    /** @param string|list<array<string, string>> $input */
    private function request(string|array $input, string $aspectRatio): GeneratedImage
    {
        if (trim($this->apiKey) === '') {
            throw new DishImageGenerationException(DishImageFailure::NotConfigured, 'GEMINI_IMAGE_API_KEY is empty');
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                    'Api-Revision' => self::API_REVISION,
                ],
                'json' => [
                    'model' => $this->model,
                    'input' => $input,
                    'response_format' => ['type' => 'image', 'aspect_ratio' => $aspectRatio, 'image_size' => '1K'],
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(throw: false);
        } catch (TimeoutExceptionInterface $e) {
            throw new DishImageGenerationException(DishImageFailure::Timeout, 'Gemini image request timed out', $e);
        } catch (HttpExceptionInterface $e) {
            // max_duration surfaces as a transport error mentioning the duration.
            $failure = str_contains(strtolower($e->getMessage()), 'duration') ? DishImageFailure::Timeout : DishImageFailure::Unavailable;
            throw new DishImageGenerationException($failure, 'Gemini image request failed: ' . $e->getMessage(), $e);
        }

        if ($status === 429 || $status >= 500) {
            throw new DishImageGenerationException(DishImageFailure::Unavailable, 'Gemini image HTTP ' . $status);
        }
        if ($status >= 400) {
            // 400 for a prompt the API won't take is a refusal of this dish;
            // 401/403 is our key — not the dish's fault, retry won't help either.
            $failure = in_array($status, [401, 403], true) ? DishImageFailure::NotConfigured : DishImageFailure::Refused;
            throw new DishImageGenerationException($failure, 'Gemini image HTTP ' . $status . ': ' . ($body['error']['message'] ?? ''));
        }

        $data = self::imageData($body);
        $bytes = $data !== null ? base64_decode($data, true) : false;
        if ($bytes === false || $bytes === '') {
            throw new DishImageGenerationException(DishImageFailure::Refused, 'Gemini returned no image');
        }

        return new GeneratedImage($bytes);
    }

    /** output_image.data, else the first image block of a model_output step. */
    private static function imageData(array $body): ?string
    {
        if (is_string($body['output_image']['data'] ?? null)) {
            return $body['output_image']['data'];
        }
        foreach ($body['steps'] ?? [] as $step) {
            if (($step['type'] ?? null) !== 'model_output') {
                continue;
            }
            foreach ($step['content'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'image' && is_string($block['data'] ?? null)) {
                    return $block['data'];
                }
            }
        }

        return null;
    }
}
