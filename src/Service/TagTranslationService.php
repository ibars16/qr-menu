<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Message\TranslateTagsMessage;
use App\Repository\ProductTagRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Resolves tag display names for the customer-facing menu.
 *
 * For each tag, returns the name in the requested locale if a translation
 * exists, or the restaurant's default language as a fallback.
 *
 * When translations are missing, a single background job is dispatched
 * so they will be permanently stored before the next request arrives —
 * avoiding repeated AI calls and ensuring the fallback is temporary.
 */
final class TagTranslationService
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ProductTagRepository $productTagRepository,
    ) {}

    /**
     * Returns a [tagId => displayName] map for all restaurant tags.
     *
     * Dispatches at most one TranslateTagsMessage if any translation is absent.
     *
     * $cacheKeyPrefix is opt-in — only the public menu (MenuController)
     * passes one; every other caller keeps warming live, uncached.
     *
     * @return array<int, string>
     */
    public function resolveForMenu(Restaurant $restaurant, string $locale, ?string $cacheKeyPrefix = null): array
    {
        $defaultLocale = $restaurant->getDefaultLanguage();
        $names         = [];
        $hasMissing    = false;

        // One query for every tag's translations, instead of the
        // per-tag lazy load getTranslation() below would otherwise
        // trigger — see ProductTagRepository::warmTranslations().
        $this->productTagRepository->warmTranslations($restaurant->getProductTags()->toArray(), $cacheKeyPrefix);

        foreach ($restaurant->getProductTags() as $tag) {
            $translation = $tag->getTranslation($locale);

            if ($translation !== null) {
                $names[$tag->getId()] = $translation->getName();
                continue;
            }

            // Fallback: show the default-language name while the async job runs.
            $fallback = $tag->getTranslation($defaultLocale);
            if ($fallback !== null) {
                $names[$tag->getId()] = $fallback->getName();
            }

            // Only queue translation if the requested locale differs from default.
            if ($locale !== $defaultLocale) {
                $hasMissing = true;
            }
        }

        if ($hasMissing) {
            $this->bus->dispatch(new TranslateTagsMessage($restaurant->getId(), $locale));
        }

        return $names;
    }
}
