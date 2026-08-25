<?php

namespace App\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;
use Psr\Log\LoggerInterface;

/**
 * Translates dish name/description pairs, preferring the free groq-llama
 * provider (Llama 3.3 70B via Groq — see AIProviderFactory::getProviderById())
 * before falling back to AIModelRouter's normal 'complex' routing
 * (capable tier — gpt-4o-mini, mistral-large, deepseek-chat — then fast
 * tier, ending at gemini-flash-lite). Deliberately NOT added to
 * ai_providers.yaml's shared "capable"/"fast" tiers, which would also
 * change what Smart Waiter tries first for its own conversations.
 *
 * A single translation pass can still produce a bad individual dish even
 * from a strong model — real, observed failures: two source words fused
 * into one non-word ("ibericogris"), or an outright fabricated word
 * ("klippektoppektopus" for "rock octopus"). translate() always runs a
 * second pass afterwards (verifyAndFix()) that shows the same model its own
 * output next to the Spanish source and asks it to catch and correct
 * exactly that kind of error. No human ever sees or fixes this — it's part
 * of the one-time-per-locale warm-up (see ProductTranslationService), so
 * paying for a second AI call there is a one-off cost, forever, same
 * reasoning as everything else in this cache.
 *
 * Sends every dish missing a given locale in a single API call, keyed by
 * product ID (not by name text) so identically-named dishes with different
 * descriptions never collide.
 */
final class RouterProductTranslator implements ProductTranslatorInterface
{
    public function __construct(
        private readonly AIModelRouter     $router,
        private readonly AIProviderFactory $providerFactory,
        private readonly LoggerInterface   $logger,
    ) {}

    public function translate(array $products, string $targetLocale): array
    {
        if (empty($products)) {
            return [];
        }

        $items = [];
        foreach ($products as $id => $p) {
            $items[] = [
                'id'          => (string) $id,
                'name'        => $p['name'],
                'description' => $p['description'] ?? '',
            ];
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        $systemPrompt = 'You are a restaurant menu translator. '
            . "Translate the \"name\" and \"description\" of each dish below into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Keep dish names natural for a menu (do not translate proper nouns / brand names). '
            . 'If "description" is empty, return it as an empty string. '
            . 'Return ONLY a valid JSON object mapping each "id" to {"name": ..., "description": ...} — no other text, '
            . 'no markdown code fences, no explanations.';

        $content = $this->complete($systemPrompt, "Dishes: {$itemsJson}", 4096);
        if ($content === null) {
            return [];
        }

        $translated = $this->parse($content);
        if (empty($translated)) {
            return [];
        }

        return $this->verifyAndFix($translated, $products, $targetLocale);
    }

    /**
     * Shows the model its own translation next to the Spanish source and
     * asks it to catch fabricated/garbled/merged words or mistranslations
     * and fix them. If this pass fails outright (no provider available),
     * the first-pass translation is kept as-is rather than discarded — an
     * unverified translation still beats none.
     *
     * @param array<int, array{name: string, description: ?string}> $translated
     * @param array<int, array{name: string, description: ?string}> $sourceProducts
     * @return array<int, array{name: string, description: ?string}>
     */
    private function verifyAndFix(array $translated, array $sourceProducts, string $targetLocale): array
    {
        $items = [];
        foreach ($translated as $id => $pair) {
            $items[] = [
                'id'                     => (string) $id,
                'source_name'            => $sourceProducts[$id]['name'] ?? '',
                'translated_name'        => $pair['name'],
                'translated_description' => $pair['description'] ?? '',
            ];
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        $systemPrompt = 'You are a translation quality checker for a restaurant menu. '
            . "You'll see, for each dish, its original Spanish name and a candidate translation into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'For each one, check the candidate translation carefully: is "translated_name" and "translated_description" made of real, correctly-spelled words that actually exist in that language — not a fabricated string, and not two real words merged together with no space between them? Is it a fluent, natural, faithful translation of the Spanish original? '
            . 'If a candidate is already correct, return it completely unchanged. If it has a problem, replace it with a corrected translation. '
            . 'Return ONLY a valid JSON object mapping each "id" to {"name": ..., "description": ...} — no other text, no markdown code fences, no explanations.';

        $content = $this->complete($systemPrompt, "Dishes: {$itemsJson}", 4096);
        if ($content === null) {
            return $translated;
        }

        $fixed = $this->parse($content);

        return !empty($fixed) ? $fixed : $translated;
    }

    /**
     * Tries the free groq-llama provider first, falling back to
     * AIModelRouter's normal chain — shared by both the translate and
     * verify passes. Returns null only once every option has failed.
     */
    private function complete(string $systemPrompt, string $userContent, int $maxTokens): ?string
    {
        $request = new AIRequest(
            systemPrompt: $systemPrompt,
            messages: [new ChatMessage('user', $userContent)],
            maxTokens: $maxTokens,
            temperature: 0.1,
        );

        $freeProvider = $this->providerFactory->getProviderById('groq-llama');
        if ($freeProvider !== null) {
            try {
                return $freeProvider->complete($request)->content;
            } catch (AIProviderException $e) {
                $this->logger->warning('groq-llama call failed, falling back to AIModelRouter', [
                    'reason'  => $e->reason->value,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $result = $this->router->route($request, 'complex');

        return $result->isSuccess() ? $result->response->content : null;
    }

    /** @return array<int, array{name: string, description: ?string}> */
    private function parse(string $text): array
    {
        // A capable-tier model asked for JSON-only sometimes still wraps it
        // in a markdown code fence — strip it defensively, same as before.
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', trim($text));

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $id => $pair) {
            if (!is_array($pair) || !isset($pair['name'])) {
                continue;
            }
            $result[(int) $id] = [
                'name'        => (string) $pair['name'],
                'description' => isset($pair['description']) && $pair['description'] !== ''
                    ? (string) $pair['description']
                    : null,
            ];
        }

        return $result;
    }
}
