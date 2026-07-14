<?php

namespace App\Service\Classification;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Classifies a batch of subjects via Google Gemini in one API call.
 *
 * Only ever invoked from ClassificationRunner, which is itself only ever
 * invoked from the manual `classify:run` console command — never on a
 * request path. See ClassificationTaskInterface for the "never invent a
 * label" safety gate; this class additionally instructs the model itself
 * to prefer leaving an item unlabeled over guessing.
 *
 * Uses gemini-flash-lite-latest, Google's floating alias for its current
 * lightweight free-tier model. A dated snapshot (gemini-2.0-flash-lite) was
 * used here previously; Google zeroed that generation's free-tier quota on
 * this project, so this now tracks the same self-updating alias
 * config/ai_providers.yaml uses for Smart Waiter, for the same reason.
 */
final class GeminiClassifierProvider implements AiClassifierProviderInterface
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-lite-latest:generateContent';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $geminiApiKey,
    ) {}

    public function classify(array $items, string $instructions, array $labelVocabulary): array
    {
        if (empty($items)) {
            return [];
        }

        $prompt = $this->buildPrompt($items, $instructions, $labelVocabulary);

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'query'   => ['key' => $this->geminiApiKey],
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'maxOutputTokens'  => 8192,
                    'temperature'      => 0.1,
                ],
            ],
            'timeout' => 60,
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
        if (!is_array($decoded)) {
            return [];
        }

        return $this->parseResults($items, $decoded);
    }

    /** @param array<int, string> $items */
    private function buildPrompt(array $items, string $instructions, array $labelVocabulary): string
    {
        $vocabLine = empty($labelVocabulary)
            ? 'There is no fixed label vocabulary for this task — propose an appropriate label as free text.'
            : 'Use ONLY these exact label codes — never invent a new one: ' . implode(', ', $labelVocabulary) . '.';

        $itemLines = [];
        foreach ($items as $id => $text) {
            $itemLines[] = json_encode((string) $id) . ': ' . json_encode($text);
        }

        return <<<PROMPT
            {$instructions}

            {$vocabLine}

            For each item below, decide which labels apply. Respond with ONLY a valid JSON object mapping each item's id (as given, as a string key) to an object of this exact shape:
            {"labels": [{"label": "<code>", "confidence": <0.0-1.0>}], "no_label_confidence": <0.0-1.0 or null>}

            Rules:
            - "labels" is an empty array if nothing applies to this item.
            - "no_label_confidence" is only meaningful when "labels" is empty: how confident you are that NONE of the labels apply, as opposed to being unsure. If you are genuinely unsure, leave "labels" empty AND set "no_label_confidence" low (below 0.5) rather than guessing.
            - Every label entry must include a numeric "confidence" between 0 and 1 reflecting your actual certainty — do not default to 1.0.
            - Include exactly one JSON object entry per item id below, and no items that aren't listed.

            Items:
            {$this->formatItems($itemLines)}

            Return exactly one JSON object, nothing else — no markdown, no explanation.
            PROMPT;
    }

    private function formatItems(array $itemLines): string
    {
        return implode("\n", $itemLines);
    }

    /**
     * @param array<int, string> $items
     * @return array<int, ClassifierItemResult>
     */
    private function parseResults(array $items, array $decoded): array
    {
        $results = [];

        foreach ($items as $id => $text) {
            // A missing entry is treated the same as genuine uncertainty —
            // no result at all, rather than fabricating an empty-but-confident
            // one on the model's behalf.
            $raw = $decoded[(string) $id] ?? null;
            if (!is_array($raw)) {
                continue;
            }

            $labels = [];
            foreach ((array) ($raw['labels'] ?? []) as $labelRow) {
                if (!is_array($labelRow) || !isset($labelRow['label'])) {
                    continue;
                }
                $confidence = isset($labelRow['confidence']) ? max(0.0, min(1.0, (float) $labelRow['confidence'])) : 0.0;
                $attributes = $labelRow;
                unset($attributes['label'], $attributes['confidence']);

                $labels[] = [
                    'label'      => (string) $labelRow['label'],
                    'confidence' => $confidence,
                    'attributes' => $attributes,
                ];
            }

            $noLabelConfidence = isset($raw['no_label_confidence']) && is_numeric($raw['no_label_confidence'])
                ? max(0.0, min(1.0, (float) $raw['no_label_confidence']))
                : null;

            $results[$id] = new ClassifierItemResult($labels, $noLabelConfidence);
        }

        return $results;
    }
}
