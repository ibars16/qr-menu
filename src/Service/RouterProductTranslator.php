<?php

namespace App\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
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
 * output next to the source text and asks it to catch and correct
 * exactly that kind of error. No human ever sees or fixes this — it's part
 * of the one-time-per-locale warm-up (see ProductTranslationService), so
 * paying for a second AI call there is a one-off cost, forever, same
 * reasoning as everything else in this cache.
 *
 * Sends every dish missing a given locale in a single API call, keyed by
 * product ID (not by name text) so identically-named dishes with different
 * descriptions never collide. Each item carries the language its text is
 * written in ("source_locale") — never assumed to be Spanish, and a batch
 * may mix languages (OwnerTextTranslationService's owner texts + ingredients).
 */
final class RouterProductTranslator implements ProductTranslatorInterface, IndependentReviewTranslator
{
    private readonly TranslationAiCaller $caller;

    /** The review pass goes to another AI than the translator (withIndependentReview()). */
    private bool $independentReview = false;

    public function __construct(
        AIModelRouter     $router,
        AIProviderFactory $providerFactory,
        LoggerInterface   $logger,
    ) {
        $this->caller = new TranslationAiCaller($router, $providerFactory, $logger);
    }

    public function withIndependentReview(): static
    {
        $copy = clone $this;
        $copy->independentReview = true;

        return $copy;
    }

    public function translate(array $products, string $targetLocale, array $sourceLocales = []): array
    {
        if (empty($products)) {
            return [];
        }

        $items = [];
        foreach ($products as $id => $p) {
            $item = [
                'id'          => (string) $id,
                'name'        => $p['name'],
                'description' => $p['description'] ?? '',
            ];
            if (isset($sourceLocales[$id])) {
                $item['source_locale'] = $sourceLocales[$id];
            }
            if (($p['section'] ?? '') !== '') {
                $item['menu_section'] = $p['section'];
            }
            $items[] = $item;
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        $systemPrompt = 'You are a restaurant menu translator. '
            . "Translate the \"name\" and \"description\" of each dish below into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Each dish is written in the language whose ISO 639-1 code is its "source_locale" — translate from that language. '
            . 'Keep dish names natural for a menu. '
            . TranslationGuidelines::DISH . ' '
            . 'If "description" is empty, return it as an empty string. '
            . 'Return ONLY a valid JSON object mapping each "id" to {"name": ..., "description": ...} — no other text, '
            . 'no markdown code fences, no explanations.';

        $answer = $this->caller->complete($systemPrompt, "Dishes: {$itemsJson}", 4096);
        if ($answer === null) {
            return [];
        }

        $translated = $this->parse($answer['content']);
        if (empty($translated)) {
            return [];
        }

        return $this->verifyAndFix($translated, $products, $targetLocale, $sourceLocales, $answer['provider']);
    }

    /**
     * Shows the model its own translation next to the source text (and the
     * language it's written in) and
     * asks it to catch fabricated/garbled/merged words or mistranslations
     * and fix them. If this pass fails outright (no provider available),
     * the first-pass translation is kept as-is rather than discarded — an
     * unverified translation still beats none.
     *
     * Its answer is MERGED into the first pass, never substituted for it:
     * despite the prompt, a model may return only the items it changed (or
     * blank some), and replacing the whole result with that silently lost
     * every other translation. Only an item the check returns with a
     * non-empty name overrides its first-pass translation; anything it
     * omits or leaves empty keeps the first pass (a blank description in a
     * correction keeps the first-pass description too).
     *
     * @param array<int, array{name: string, description: ?string}> $translated
     * @param array<int, array{name: string, description: ?string}> $sourceProducts
     * @param array<int, string> $sourceLocales
     * @return array<int, array{name: string, description: ?string}>
     */
    private function verifyAndFix(array $translated, array $sourceProducts, string $targetLocale, array $sourceLocales, string $translatedBy): array
    {
        $items = [];
        foreach ($translated as $id => $pair) {
            $item = [
                'id'                     => (string) $id,
                'source_name'            => $sourceProducts[$id]['name'] ?? '',
                // The original description too: without it the check
                // can't compare the translated description's meaning.
                'source_description'     => $sourceProducts[$id]['description'] ?? '',
                'translated_name'        => $pair['name'],
                'translated_description' => $pair['description'] ?? '',
            ];
            if (isset($sourceLocales[$id])) {
                $item['source_locale'] = $sourceLocales[$id];
            }
            if (($sourceProducts[$id]['section'] ?? '') !== '') {
                $item['menu_section'] = $sourceProducts[$id]['section'];
            }
            $items[] = $item;
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        $systemPrompt = 'You are a translation quality checker for a restaurant menu. '
            . "You'll see, for each dish, its original name and description (\"source_name\", \"source_description\" — written in the language whose ISO 639-1 code is its \"source_locale\") and a candidate translation into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'For each one, check the candidate translation carefully: is "translated_name" and "translated_description" made of real, correctly-spelled words that actually exist in that language — not a fabricated string, and not two real words merged together with no space between them? Is it a fluent, natural, faithful translation of the original? '
            . TranslationGuidelines::DISH_REVIEW . ' '
            . 'If a candidate is already correct, return it completely unchanged. If it has a problem, replace it with a corrected translation. '
            . 'Return ONLY a valid JSON object mapping each "id" to {"name": ..., "description": ...} — no other text, no markdown code fences, no explanations.';

        $content = $this->independentReview
            ? $this->caller->completeElsewhere($systemPrompt, "Dishes: {$itemsJson}", 4096, $translatedBy)
            : $this->caller->complete($systemPrompt, "Dishes: {$itemsJson}", 4096)['content'] ?? null;
        if ($content === null) {
            return $translated;
        }

        $merged = $translated;
        foreach ($this->parse($content) as $id => $pair) {
            if (!isset($merged[$id]) || trim($pair['name']) === '') {
                continue; // unknown id, or blanked by the check: keep the first pass
            }
            $merged[$id] = [
                'name'        => $pair['name'],
                'description' => $pair['description'] ?? $merged[$id]['description'],
            ];
        }

        return $merged;
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
