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
 *
 * The same name is translated the same way (TranslationReuse, as for
 * dishes): a tag whose source (its language and exact name) is the same
 * as another tag's already translated into $locale gets a copy of that
 * translation, no AI call; tags with the same source still missing are
 * sent once. Copies are 'ai' rows with "insert if missing" too.
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

        // Same source as a tag already translated into $locale: copied.
        $stored = $this->copyTranslated($restaurant, $locale, $names, $sourceLocales);

        // Same source as another tag still missing: sent once.
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
                if ($result === '') {
                    continue;
                }
                foreach ($copiesOf[$tagId] as $sameSourceId) {
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

    /** @return ProductTag[] */
    /**
     * Stores, for each tag in $names, a copy of the $locale translation of
     * another tag of the restaurant with exactly the same source (see
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
        foreach ($restaurant->getProductTags() as $other) {
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
