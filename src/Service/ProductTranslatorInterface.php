<?php

namespace App\Service;

/**
 * Contract for AI-backed dish name/description translation.
 *
 * Implementations are interchangeable: swapping providers (Gemini → OpenAI, etc.)
 * requires only a new class; no business logic changes elsewhere.
 */
interface ProductTranslatorInterface
{
    /**
     * Translates a batch of dish name/description pairs into $targetLocale
     * in one API call.
     *
     * @param  array<int, array{name: string, description: ?string}> $products
     *         map of [productId => ['name' => ..., 'description' => ...]]
     * @param  string $targetLocale  ISO 639-1 language code
     * @param  array<int, string> $sourceLocales  map of [productId => ISO 639-1
     *         code the source text is written in] — never assumed, a batch
     *         may mix languages (see OwnerTextTranslationService)
     * @return array<int, array{name: string, description: ?string}>
     *         map of [productId => ['name' => ..., 'description' => ...]]
     */
    public function translate(array $products, string $targetLocale, array $sourceLocales = []): array;
}
