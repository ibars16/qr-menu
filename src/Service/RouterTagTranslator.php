<?php

namespace App\Service;

/**
 * Translates a restaurant's tag names (the short badges on dishes —
 * "Casero", "Picante", "Sin gluten"…) through RouterLabelTranslator, the
 * same provider chain, verify-and-fix pass and merge as category names.
 *
 * The prompts insist on TRANSLATING: the previous single-pass Gemini
 * translator, given a misspelled Spanish tag, sometimes "fixed" the typo
 * and returned the Spanish word as the Italian translation ("espárago",
 * "alérgeno"). The check pass is told to treat a source-language answer as
 * wrong unless the word is genuinely the same in both languages.
 */
final class RouterTagTranslator implements TagTranslatorInterface
{
    public function __construct(
        private readonly RouterLabelTranslator $labelTranslator,
    ) {}

    public function translate(array $names, string $targetLocale, array $sourceLocales = []): array
    {
        $translatePrompt = 'You are a translator for the tags of a restaurant menu — short labels shown on dishes (dietary, allergen, style or origin badges). '
            . "Translate the \"name\" of each tag below into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Each name is written in the language whose ISO 639-1 code is its "source_locale" — translate from that language. '
            . 'Always TRANSLATE into that language, even when the original name has a typo or a misspelling: work out the intended word and translate it — never just correct the spelling and keep it in the original language. '
            . 'Never return a name in the original language, unless the word is genuinely the same in both languages (e.g. "carne" in Italian). '
            . 'Keep translations short (1-3 words), natural for a menu label. '
            . 'Return ONLY a valid JSON object mapping each "id" to its translated name (a plain string, not an object) — '
            . 'no other text, no markdown code fences, no explanations.';

        $verifyPrompt = 'You are a translation quality checker for the tags of a restaurant menu — short labels shown on dishes. '
            . "You'll see, for each tag, its original name (written in the language whose ISO 639-1 code is its \"source_locale\") and a candidate translation into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Check the candidate carefully. Is it actually in that language? A candidate left in the original language — including a spelling-corrected copy of a misspelled original — is wrong, unless the word is genuinely the same in both languages (e.g. "carne" in Italian). '
            . 'Is it made of real, correctly-spelled words that actually exist in that language — not a fabricated string, and not two real words merged together with no space? Is it a natural, faithful translation? '
            . 'If a candidate is already correct, return it completely unchanged. If it has a problem, replace it with a corrected translation into that language. '
            . 'Return ONLY a valid JSON object mapping each "id" to its translated name (a plain string, not an object) — no other text, no markdown code fences, no explanations.';

        return $this->labelTranslator->translate($names, $translatePrompt, $verifyPrompt, 'Tags', 2048, $sourceLocales);
    }
}
