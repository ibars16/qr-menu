<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Restaurant;
use App\Repository\CategoryTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates the find-missing → translate → persist cycle for category-name
 * translations. AI provider details are fully encapsulated in
 * CategoryTranslatorInterface; this class is agnostic to which backend is
 * wired in the service container.
 *
 * A restaurant's category list is small (a handful to a few dozen rows), so
 * — like AiTagTranslator — this walks Restaurant::getCategories() in memory
 * rather than issuing a dedicated repository query.
 *
 * Batches, deadline, "insert if missing" and the menu content version bump:
 * same as AiProductTranslator, with BATCH names per call.
 */
final class AiCategoryTranslator
{
    /** Names per AI call — same as MenuLanguageChanger. */
    public const BATCH = 60;

    public function __construct(
        private readonly CategoryTranslatorInterface   $translator,
        private readonly EntityManagerInterface        $em,
        private readonly CategoryTranslationRepository $translationRepo,
        private readonly LoggerInterface               $logger,
    ) {}

    /** @return int how many translations it stored */
    public function translateMissing(Restaurant $restaurant, string $locale, ?TranslationDeadline $deadline = null): int
    {
        $missing = $this->findCategoriesMissingTranslation($restaurant, $locale);
        if (empty($missing)) {
            return 0;
        }

        [$names, $sourceLocales] = $this->buildNameMap($missing, $restaurant->getDefaultLanguage(), $locale);

        $stored = 0;
        foreach (array_chunk($names, self::BATCH, true) as $batch) {
            if ($deadline?->isPassed()) {
                $this->logger->info('Category translation: out of time, the rest is left for the next visit.', [
                    'restaurantId' => $restaurant->getId(),
                    'locale'       => $locale,
                ]);
                break;
            }

            try {
                $translated = $this->translator->translate($batch, $locale, array_intersect_key($sourceLocales, $batch));
            } catch (\Throwable $e) {
                $this->logger->warning('Category translation batch failed; it stays missing.', [
                    'restaurantId' => $restaurant->getId(),
                    'locale'       => $locale,
                    'exception'    => $e,
                ]);
                continue;
            }

            $storedNow = 0;
            foreach (array_keys($batch) as $categoryId) {
                $result = trim((string) ($translated[$categoryId] ?? ''));
                if ($result !== '' && $this->translationRepo->insertAiIfMissing($categoryId, $locale, $result)) {
                    $storedNow++;
                }
            }

            if ($storedNow > 0) {
                $restaurant->bumpMenuContentVersion();
                $this->em->flush();
                $stored += $storedNow;
            }
        }

        return $stored;
    }

    /** @return Category[] */
    private function findCategoriesMissingTranslation(Restaurant $restaurant, string $locale): array
    {
        $missing = [];
        foreach ($restaurant->getCategories() as $category) {
            if ($category->isActive() && $category->getTranslation($locale) === null) {
                $missing[] = $category;
            }
        }

        return $missing;
    }

    /**
     * Source name per category — see TranslationSource::pick() — with its
     * real language.
     *
     * @param  Category[] $categories
     * @return array{0: array<int, string>, 1: array<int, string>}  [categoryId → source name, categoryId → source locale]
     */
    private function buildNameMap(array $categories, string $defaultLocale, string $targetLocale): array
    {
        $map     = [];
        $locales = [];
        foreach ($categories as $category) {
            $source = TranslationSource::pick($category->getTranslations(), $defaultLocale, $targetLocale);
            if ($source !== null) {
                $map[$category->getId()]     = $source->getName();
                $locales[$category->getId()] = $source->getLocale();
            }
        }

        return [$map, $locales];
    }
}
