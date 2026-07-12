<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Translates Global Ingredient Library names via Google Gemini.
 *
 * Uses gemini-2.0-flash-lite — the most cost-effective Gemini model — and
 * sends a batch of names for a given locale in a single API call to
 * minimise cost. Only ever invoked from GlobalIngredientTranslationBackfiller
 * during import — never on a request path.
 */
final class GeminiIngredientTranslator implements IngredientTranslatorInterface
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string             $geminiApiKey,
    ) {}

    public function translate(array $names, string $targetLocale): array
    {
        if (empty($names)) {
            return [];
        }

        $uniqueNames = array_values(array_unique($names));
        $list        = implode(', ', array_map(fn($n) => '"' . addslashes($n) . '"', $uniqueNames));

        $prompt = 'You are translating ingredient names for a restaurant/food-product database '
            . '(e.g. "Extra virgin olive oil", "Canned concentrated tomato paste"). '
            . "Translate each one into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Preserve the full meaning — do not shorten, drop qualifiers, or paraphrase; a short '
            . 'name should stay short and a longer descriptive name should stay just as complete. '
            . 'Return ONLY a valid JSON object mapping each original name to its translation. '
            . 'Do not add explanations or markdown.'
            . "\n\nNames: {$list}";

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'query'   => ['key' => $this->geminiApiKey],
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'maxOutputTokens'  => 4096,
                    'temperature'      => 0.1,
                ],
            ],
        ]);

        $body = $response->toArray(throw: false);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (trim($text) === '') {
            return [];
        }

        // Gemini may wrap the JSON in a markdown code block even when
        // responseMimeType is set — strip it defensively.
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', trim($text));

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
