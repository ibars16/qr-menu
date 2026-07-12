<?php

namespace App\Service;

use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use App\Repository\GlobalIngredientRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fills gaps in the Global Ingredient Library's translations using AI, once,
 * at import time — never at request time. Run only from
 * ImportGlobalIngredientsCommand; nothing in the request path calls this.
 */
final class GlobalIngredientTranslationBackfiller
{
    // Keeps each API call's prompt small enough to translate reliably and
    // cheaply, and bounds how much work is lost if one call fails.
    private const CHUNK_SIZE = 50;

    public function __construct(
        private readonly IngredientTranslatorInterface $translator,
        private readonly EntityManagerInterface         $em,
        private readonly GlobalIngredientRepository     $repository,
    ) {}

    /**
     * Translates every ingredient missing a $targetLocale name from its
     * $sourceLocale name, persisting each chunk as it completes so a later
     * failure (rate limit, network) doesn't lose earlier progress. Safe to
     * re-run: already-translated ingredients are skipped.
     *
     * @param  callable(int,int):void|null $onChunk  invoked after each chunk with (translated, failed) totals so far
     * @return array{translated: int, failed: int, skippedNoSource: int}
     */
    public function backfill(string $targetLocale, string $sourceLocale, ?callable $onChunk = null): array
    {
        $missingIds = $this->repository->findIdsMissingTranslation($targetLocale);

        $translated      = 0;
        $failed          = 0;
        $skippedNoSource = 0;

        foreach (array_chunk($missingIds, self::CHUNK_SIZE) as $idChunk) {
            $ingredients = $this->repository->findBy(['id' => $idChunk]);

            /** @var array<int, string> $namesById */
            $namesById = [];
            foreach ($ingredients as $ingredient) {
                $source = $ingredient->getTranslation($sourceLocale);
                if ($source !== null && trim($source->getName()) !== '') {
                    $namesById[$ingredient->getId()] = $source->getName();
                } else {
                    $skippedNoSource++;
                }
            }

            if (empty($namesById)) {
                continue;
            }

            try {
                $translatedByName = $this->translator->translate($namesById, $targetLocale);
            } catch (\Throwable) {
                // A failed chunk must not abort the whole import — the
                // affected ingredients simply stay untranslated and will be
                // picked up again the next time this command runs.
                $failed += count($namesById);
                $onChunk?->__invoke($translated, $failed);
                continue;
            }

            foreach ($ingredients as $ingredient) {
                $sourceName = $namesById[$ingredient->getId()] ?? null;
                if ($sourceName === null) {
                    continue;
                }

                $result = $translatedByName[$sourceName] ?? null;
                if ($result === null || trim($result) === '') {
                    $failed++;
                    continue;
                }

                $this->addTranslation($ingredient, $targetLocale, trim($result));
                $translated++;
            }

            $this->em->flush();
            $this->em->clear();
            $onChunk?->__invoke($translated, $failed);
        }

        return ['translated' => $translated, 'failed' => $failed, 'skippedNoSource' => $skippedNoSource];
    }

    private function addTranslation(GlobalIngredient $ingredient, string $locale, string $name): void
    {
        // em->clear() detaches entities between chunks, but never mid-chunk,
        // so this guard is only ever needed for a genuinely fresh ingredient.
        if ($ingredient->getTranslation($locale) !== null) {
            return;
        }

        $translation = new GlobalIngredientTranslation();
        $translation->setLocale($locale);
        $translation->setName($name);
        $ingredient->addTranslation($translation);
        $this->em->persist($translation);
    }
}
