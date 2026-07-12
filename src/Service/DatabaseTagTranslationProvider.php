<?php

namespace App\Service;

use App\Entity\ProductTag;

/**
 * Retrieves tag translations from the in-memory Doctrine collection.
 *
 * Because the collection is already initialised when tags are loaded as part
 * of a restaurant's product tree, this causes zero extra queries.
 *
 * To add caching later: implement TagTranslationProviderInterface in a
 * CachedTagTranslationProvider that wraps this class, then configure the
 * Symfony DI container to decorate this service with the cached version.
 */
final class DatabaseTagTranslationProvider implements TagTranslationProviderInterface
{
    public function getName(ProductTag $tag, string $locale): ?string
    {
        return $tag->getTranslation($locale)?->getName();
    }
}
