<?php

namespace App\Service;

use App\Entity\ProductTag;
use App\Entity\Restaurant;
use App\Repository\ProductTagTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates the find-missing → translate → persist cycle for tag translations.
 *
 * AI provider details are fully encapsulated in TagTranslatorInterface; this
 * class is agnostic to which backend is wired in the service container.
 *
 * Batches, deadline, "insert if missing" and the menu content version bump:
 * same as AiProductTranslator, with BATCH names per call.
 */
final class AiTagTranslator
{
    /** Names per AI call — same as MenuLanguageChanger. */
    public const BATCH = 60;

    public function __construct(
        private readonly TagTranslatorInterface          $translator,
        private readonly EntityManagerInterface          $em,
        private readonly ProductTagTranslationRepository $translationRepo,
        private readonly LoggerInterface                 $logger,
    ) {}

    /**
     * Finds all tags for the restaurant that lack a translation in $locale
     * and translates them batch by batch (each plus its check pass).
     *
     * @return int how many translations it stored
     */
    public function translateMissing(Restaurant $restaurant, string $locale, ?TranslationDeadline $deadline = null): int
    {
        $missing = $this->findTagsMissingTranslation($restaurant, $locale);
        if (empty($missing)) {
            return 0;
        }

        [$names, $sourceLocales] = $this->buildNameMap($missing, $restaurant->getDefaultLanguage(), $locale);

        $stored = 0;
        foreach (array_chunk($names, self::BATCH, true) as $batch) {
            if ($deadline?->isPassed()) {
                $this->logger->info('Tag translation: out of time, the rest is left for the next visit.', [
                    'restaurantId' => $restaurant->getId(),
                    'locale'       => $locale,
                ]);
                break;
            }

            try {
                $translated = $this->translator->translate($batch, $locale, array_intersect_key($sourceLocales, $batch));
            } catch (\Throwable $e) {
                $this->logger->warning('Tag translation batch failed; it stays missing.', [
                    'restaurantId' => $restaurant->getId(),
                    'locale'       => $locale,
                    'exception'    => $e,
                ]);
                continue;
            }

            $storedNow = 0;
            foreach (array_keys($batch) as $tagId) {
                $result = trim((string) ($translated[$tagId] ?? ''));
                if ($result !== '' && $this->translationRepo->insertAiIfMissing($tagId, $locale, $result)) {
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

    /** @return ProductTag[] */
    private function findTagsMissingTranslation(Restaurant $restaurant, string $locale): array
    {
        $missing = [];
        foreach ($restaurant->getProductTags() as $tag) {
            if ($tag->getTranslation($locale) === null) {
                $missing[] = $tag;
            }
        }

        return $missing;
    }

    /**
     * Source name per tag — see TranslationSource::pick() — with its real
     * language.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}  [tagId → source name, tagId → source locale]
     */
    private function buildNameMap(array $tags, string $defaultLocale, string $targetLocale): array
    {
        $map     = [];
        $locales = [];
        foreach ($tags as $tag) {
            $source = TranslationSource::pick($tag->getTranslations(), $defaultLocale, $targetLocale);
            if ($source !== null) {
                $map[$tag->getId()]     = $source->getName();
                $locales[$tag->getId()] = $source->getLocale();
            }
        }

        return [$map, $locales];
    }
}
