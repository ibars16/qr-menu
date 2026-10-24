<?php

namespace App\Service;

/**
 * Contract for AI-backed tag-name translation.
 *
 * Implementations are interchangeable: swapping providers (Gemini → OpenAI, etc.)
 * requires only a new class; no business logic changes elsewhere.
 */
interface TagTranslatorInterface
{
    /**
     * Translates a batch of tag names into $targetLocale.
     *
     * @param  array<int, string> $names  map of [tagId => sourceName]
     * @param  string             $targetLocale  ISO 639-1 language code
     * @return array<int, string>  map of [tagId => translatedName]
     */
    public function translate(array $names, string $targetLocale): array;
}
