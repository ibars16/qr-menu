<?php

namespace App\Service;

/**
 * Contract for AI-backed Global Ingredient Library translation.
 *
 * Deliberately not TagTranslatorInterface: that one's prompt caps output at
 * 1–3 words, which fits a dietary tag label but would mangle a long
 * ingredient name (e.g. "Canned concentrated tomato paste"). Same shape,
 * different constraints — implementations are interchangeable (Gemini →
 * another provider) without touching GlobalIngredientTranslationBackfiller.
 */
interface IngredientTranslatorInterface
{
    /**
     * Translates a batch of ingredient names into $targetLocale in one API call.
     *
     * @param  array<int, string> $names  map of [ingredientId => sourceName]
     * @param  string             $targetLocale  ISO 639-1 language code
     * @return array<string, string>            map of [sourceName => translatedName]
     */
    public function translate(array $names, string $targetLocale): array;
}
