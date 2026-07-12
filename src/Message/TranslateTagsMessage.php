<?php

namespace App\Message;

/**
 * Dispatched when a customer visits the menu in a locale that has no tag
 * translations yet. The handler batch-translates all missing tags for that
 * restaurant + locale in a single AI call and persists the results.
 */
final readonly class TranslateTagsMessage
{
    public function __construct(
        public readonly int    $restaurantId,
        public readonly string $locale,
    ) {}
}
