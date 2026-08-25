<?php

namespace App\Service;

/**
 * Contract for AI-backed category-name translation.
 *
 * Implementations are interchangeable: swapping providers (Gemini → OpenAI, etc.)
 * requires only a new class; no business logic changes elsewhere.
 */
interface CategoryTranslatorInterface
{
    /**
     * Translates a batch of category names into $targetLocale in one API call.
     *
     * @param  array<int, string> $names  map of [categoryId => sourceName]
     * @param  string             $targetLocale  ISO 639-1 language code
     * @return array<int, string>  map of [categoryId => translatedName]
     */
    public function translate(array $names, string $targetLocale): array;
}
