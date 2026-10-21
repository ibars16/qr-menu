<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Repository\ProductTagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tag display names for the customer-facing menu, and their translation.
 *
 * - resolveForMenu(): the name in the requested locale when a translation
 *   exists, otherwise the restaurant's default-language name. Never calls
 *   the AI and never queues anything (the render path).
 * - hasAnyMissing() / translateMissing(): the loading-screen path, same as
 *   dishes and owner texts — MenuController shows the loading screen when
 *   something is missing, and its warm-translations request translates
 *   every missing tag in one AI call per locale (AiTagTranslator).
 */
final class TagTranslationService
{
    public function __construct(
        private readonly ProductTagRepository $productTagRepository,
        private readonly AiTagTranslator      $aiTranslator,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * True when $locale isn't the default language and a tag that HAS a
     * default-language name (i.e. can be translated) has no $locale one yet.
     */
    public function hasAnyMissing(Restaurant $restaurant, string $locale, ?string $cacheKeyPrefix = null): bool
    {
        $defaultLocale = $restaurant->getDefaultLanguage();
        if ($locale === $defaultLocale) {
            return false;
        }

        $this->productTagRepository->warmTranslations($restaurant->getProductTags()->toArray(), $cacheKeyPrefix);
        foreach ($restaurant->getProductTags() as $tag) {
            if ($tag->getTranslation($defaultLocale) !== null && $tag->getTranslation($locale) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translates every missing tag into $locale (one AI call) and, if there
     * was anything to translate, bumps the menu content version: tag names
     * are read through the menu-content cache (see warmTranslations()), so
     * without the bump the new names wouldn't show until some unrelated
     * admin edit.
     */
    public function translateMissing(Restaurant $restaurant, string $locale): void
    {
        if (!$this->hasAnyMissing($restaurant, $locale)) {
            return;
        }

        $this->aiTranslator->translateMissing($restaurant, $locale);
        $restaurant->bumpMenuContentVersion();
        $this->em->flush();
    }

    /**
     * Returns a [tagId => displayName] map for all restaurant tags.
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

            // Fallback: the default-language name until the loading screen
            // translates it (translateMissing()).
            $fallback = $tag->getTranslation($defaultLocale);
            if ($fallback !== null) {
                $names[$tag->getId()] = $fallback->getName();
            }
        }

        return $names;
    }
}
