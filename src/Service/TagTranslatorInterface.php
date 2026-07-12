<?php

namespace App\Service;

/**
 * Contract for AI-backed dietary tag translation.
 *
 * Implementations are interchangeable: swapping providers (Gemini → OpenAI, etc.)
 * requires only a new class; no business logic changes elsewhere.
 */
interface TagTranslatorInterface
{
    /**
     * Translates a batch of tag names into $targetLocale in one API call.
     *
     * @param  array<string, string> $names  map of [sourceKey => sourceName] where
     *                                        sourceKey uniquely identifies the name
     *                                        (e.g. tag ID cast to string)
     * @param  string                $targetLocale  ISO 639-1 language code
     * @return array<string, string>               map of [sourceName => translatedName]
     */
    public function translate(array $names, string $targetLocale): array;
}
