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
 *
 * The same name is translated the same way (TranslationReuse, as for
 * dishes): a category whose source (its language and exact name) is the same
 * as another category's already translated into $locale gets a copy of that
 * translation, no AI call; categorys with the same source still missing are
 * sent once. Copies are 'ai' rows with "insert if missing" too.
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

        // Same source as a category already translated into $locale: copied.
        $stored = $this->copyTranslated($restaurant, $locale, $names, $sourceLocales);

        // Same source as another category still missing: sent once.
        $copiesOf = $toSend = $first = [];
        foreach ($names as $id => $name) {
            $key = TranslationReuse::key($sourceLocales[$id], $name);
            if (isset($first[$key])) {
                $copiesOf[$first[$key]][] = $id;
                continue;
            }
            $first[$key] = $id;
            $copiesOf[$id] = [$id];
            $toSend[$id] = $name;
        }

        foreach (array_chunk($toSend, self::BATCH, true) as $batch) {
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
                if ($result === '') {
                    continue;
                }
                foreach ($copiesOf[$categoryId] as $sameSourceId) {
                    if ($this->translationRepo->insertAiIfMissing($sameSourceId, $locale, $result)) {
                        $storedNow++;
                    }
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
    /**
     * Stores, for each category in $names, a copy of the $locale translation of
     * another category of the restaurant with exactly the same source (see
     * TranslationReuse), and takes it out of $names. Bumps the menu content
     * version when it stored any.
     *
     * @param  array<int, string> $names         id → source name (copied ones are removed)
     * @param  array<int, string> $sourceLocales id → its language
     * @return int how many it stored
     */
    private function copyTranslated(Restaurant $restaurant, string $locale, array &$names, array $sourceLocales): int
    {
        $known = [];
        foreach ($restaurant->getCategories() as $other) {
            $done = trim((string) $other->getTranslation($locale)?->getName());
            if ($done === '') {
                continue;
            }
            $from = TranslationSource::pick($other->getTranslations(), $restaurant->getDefaultLanguage(), $locale);
            if ($from !== null) {
                $known[TranslationReuse::key($from->getLocale(), $from->getName())] ??= $done;
            }
        }

        $stored = 0;
        foreach ($names as $id => $name) {
            $done = $known[TranslationReuse::key($sourceLocales[$id], $name)] ?? null;
            if ($done === null) {
                continue;
            }
            unset($names[$id]);
            if ($this->translationRepo->insertAiIfMissing($id, $locale, $done)) {
                $stored++;
            }
        }

        if ($stored > 0) {
            $restaurant->bumpMenuContentVersion();
            $this->em->flush();
        }

        return $stored;
    }

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
