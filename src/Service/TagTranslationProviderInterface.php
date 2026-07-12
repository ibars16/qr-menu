<?php

namespace App\Service;

use App\Entity\ProductTag;

/**
 * Returns the translated name for a tag in a given locale.
 *
 * This interface exists as the single retrieval point so that a caching
 * decorator (Redis, APCu, etc.) can be introduced later by implementing
 * this interface and wrapping DatabaseTagTranslationProvider — without
 * touching any business logic that depends on it.
 */
interface TagTranslationProviderInterface
{
    /**
     * Returns the tag name for $locale, or null if no translation exists yet.
     */
    public function getName(ProductTag $tag, string $locale): ?string;
}
