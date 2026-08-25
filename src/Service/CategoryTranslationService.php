<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Category-name twin of ProductTranslationService — same reasoning applies
 * in full (see that class's docblock): synchronous, once-ever, permanently
 * cached AI translation, invalidated only by an explicit admin edit of the
 * source-locale name.
 */
final class CategoryTranslationService
{
    public function __construct(
        private readonly AiCategoryTranslator   $aiTranslator,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * @param Category[] $categories
     */
    public function hasAnyMissing(Restaurant $restaurant, array $categories, string $locale): bool
    {
        if ($locale === $restaurant->getDefaultLanguage()) {
            return false;
        }

        foreach ($categories as $category) {
            if ($category->getTranslation($locale) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translates every given category missing a $locale translation, in one
     * AI call, before returning — a no-op if none are missing.
     *
     * @param Category[] $categories
     */
    public function resolveForMenu(Restaurant $restaurant, array $categories, string $locale): void
    {
        if (!$this->hasAnyMissing($restaurant, $categories, $locale)) {
            return;
        }

        try {
            $this->aiTranslator->translateMissing($restaurant, $locale);
        } catch (\Throwable $e) {
            $this->logger->warning('Category translation failed; falling back to default language for this request.', [
                'restaurantId' => $restaurant->getId(),
                'locale'       => $locale,
                'exception'    => $e,
            ]);
        }
    }

    /**
     * Deletes every AI-generated translation of $category other than
     * $sourceLocale itself, so they get regenerated from the new source text
     * the next time each locale is requested.
     */
    public function invalidateStale(Category $category, string $sourceLocale): void
    {
        foreach ($category->getTranslations()->toArray() as $translation) {
            if ($translation->isAiGenerated() && $translation->getLocale() !== $sourceLocale) {
                $category->removeTranslation($translation);
                $this->em->remove($translation);
            }
        }
    }
}
