<?php

namespace App\Service;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use App\Repository\ProductTagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tag display names for the customer-facing menu, and their translation.
 *
 * - resolveForMenu(): the name in the requested locale when a translation
 *   exists, otherwise the default-language, English or any other name. Never calls
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
     * True when $locale isn't the default language and a tag that can be
     * translated (has a source — see TranslationSource) has no $locale name yet.
     */
    public function hasAnyMissing(Restaurant $restaurant, string $locale, ?string $cacheKeyPrefix = null): bool
    {
        $defaultLocale = $restaurant->getDefaultLanguage();
        if ($locale === $defaultLocale) {
            return false;
        }

        $this->productTagRepository->warmTranslations($restaurant->getProductTags()->toArray(), $cacheKeyPrefix);
        foreach ($restaurant->getProductTags() as $tag) {
            if ($tag->getTranslation($locale) === null
                && TranslationSource::pick($tag->getTranslations(), $defaultLocale, $locale) !== null
            ) {
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
            // Fallback until the loading screen translates it
            // (translateMissing()): same order as the menu's ingredient names
            // (Product::ingredientDisplayTranslation()) — the default
            // language, English, then any name it has, so a tag named only
            // in another language still shows in the default language too.
            $translation = $tag->getTranslation($locale)
                ?? $tag->getTranslation($defaultLocale)
                ?? $tag->getTranslation('en')
                ?? $this->anyNamedTranslation($tag);

            if ($translation !== null) {
                $names[$tag->getId()] = $translation->getName();
            }
        }

        return $names;
    }

    private function anyNamedTranslation(ProductTag $tag): ?ProductTagTranslation
    {
        foreach ($tag->getTranslations() as $translation) {
            if (trim($translation->getName()) !== '') {
                return $translation;
            }
        }

        return null;
    }
}
