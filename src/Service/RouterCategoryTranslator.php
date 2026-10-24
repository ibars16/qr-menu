<?php

namespace App\Service;

/**
 * Translates menu category names (e.g. "Pizzas", "Postres") through
 * RouterLabelTranslator — free groq-llama first, AIModelRouter's 'complex'
 * routing as fallback, then a verify-and-fix pass merged into the first.
 * Keyed by category ID (not name text) so two restaurants' — or even one
 * restaurant's own — identically-named categories never collide.
 */
final class RouterCategoryTranslator implements CategoryTranslatorInterface
{
    public function __construct(
        private readonly RouterLabelTranslator $labelTranslator,
    ) {}

    public function translate(array $names, string $targetLocale, array $sourceLocales = []): array
    {
        $translatePrompt = 'You are a restaurant menu translator. '
            . "Translate the \"name\" of each menu category below into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Each name is written in the language whose ISO 639-1 code is its "source_locale" — translate from that language. '
            . 'Keep names short, natural for a menu section heading (1-3 words). '
            . 'Return ONLY a valid JSON object mapping each "id" to its translated name (a plain string, not an object) — '
            . 'no other text, no markdown code fences, no explanations.';

        $verifyPrompt = 'You are a translation quality checker for a restaurant menu. '
            . "You'll see, for each menu category, its original name (written in the language whose ISO 639-1 code is its \"source_locale\") and a candidate translation into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Check the candidate carefully: is it made of real, correctly-spelled words that actually exist in that language — not a fabricated string, and not two real words merged together with no space? Is it a natural, faithful translation? '
            . 'If a candidate is already correct, return it completely unchanged. If it has a problem, replace it with a corrected translation. '
            . 'Return ONLY a valid JSON object mapping each "id" to its translated name (a plain string, not an object) — no other text, no markdown code fences, no explanations.';

        return $this->labelTranslator->translate($names, $translatePrompt, $verifyPrompt, 'Categories', sourceLocales: $sourceLocales);
    }
}
