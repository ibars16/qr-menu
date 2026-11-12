<?php

namespace App\Service\DishPhoto;

use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Service\Upload\ProductImageReplacer;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

/**
 * "Crear sus fotos con IA" after an import: the dishes of THAT import with
 * no photo, one per request — the browser calls step() until done, like
 * the main-language change (no queue, no cron). Closing the window leaves
 * aiPhotosStatus 'running' and Carta offers "Continuar".
 *
 * Per dish: refused / timed out → skipped for good (aiPhotosSkipped), next
 * dish; provider down (429/5xx/network/no key), the brake or the paid
 * monthly limit → 'paused',
 * "Reintentar".
 *
 * Free trial (DishPhotoQuota::isLimited()): only the photos the trial has
 * left ("Crear 2 fotos de ejemplo"), on dishes picked at random from
 * different categories where possible; then it ends ('trial', trialEnded)
 * and, once the restaurant pays, Carta offers the rest (noticesFor()).
 */
class AiPhotoBatch
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DishPhotoGenerator $generator,
        private readonly ProductImageReplacer $replacer,
        private readonly DishPhotoAvailability $availability,
        private readonly DishPhotoQuota $quota,
    ) {
    }

    /** @return Product[] this import's dishes still without a photo, skipped ones excluded, in id order */
    public function pending(MenuImportBatch $batch): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->join('p.category', 'c')
            ->where('p.importBatch = :batch')
            ->andWhere('c.restaurant = :restaurant')
            ->andWhere('p.image IS NULL')
            ->setParameter('batch', $batch)
            ->setParameter('restaurant', $batch->getRestaurant())
            ->orderBy('p.id', 'ASC');
        if ($batch->getAiPhotosSkipped() !== []) {
            $qb->andWhere('p.id NOT IN (:skipped)')->setParameter('skipped', $batch->getAiPhotosSkipped());
        }

        return $qb->getQuery()->getResult();
    }

    /** false without an image key: no AI photos offered anywhere. */
    public function isAvailable(): bool
    {
        return $this->availability->isAvailable();
    }

    /** 0 without an image key (DishPhotoAvailability): the offer after an import isn't shown. */
    public function pendingCount(MenuImportBatch $batch): int
    {
        if (!$this->availability->isAvailable()) {
            return 0;
        }

        return count($this->pending($batch));
    }

    /**
     * One dish. Holds a row lock on the batch while it works, so a second
     * tab running the same batch waits and then picks the next dish rather
     * than paying for the same one twice.
     *
     * @return array{done: bool, generated: bool, remaining: int, skipped: int, stopped: ?string, trialEnded: bool}
     *   stopped: null, or a DishPhotoException reason that paused the batch;
     *   trialEnded: the trial's photos are used up and dishes are left (done too)
     */
    public function step(MenuImportBatch $batch): array
    {
        return $this->em->wrapInTransaction(function () use ($batch): array {
            $this->em->lock($batch, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($batch);
            $restaurant = $batch->getRestaurant();
            $trial = DishPhotoQuota::isLimited($restaurant);

            if ($batch->getAiPhotosStatus() !== MenuImportBatch::AI_PHOTOS_RUNNING) {
                $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_RUNNING);
            }

            $pending = $this->pending($batch);
            $generated = false;
            $stopped = null;
            $trialEnded = false;

            if ($pending !== [] && $trial && $this->quota->trialRemaining($restaurant) === 0) {
                $trialEnded = true;
            } elseif ($pending !== []) {
                $index = $trial ? $this->pickForTrial($pending, $this->categoriesWithAiPhoto($batch)) : 0;
                $product = $pending[$index];
                [$name, $description] = $this->texts($product, $restaurant);
                try {
                    if (trim($name) === '') {
                        throw new DishPhotoException(DishImageFailure::Refused->value);
                    }
                    $filename = $this->generator->forProduct($product, $name, $description);
                    $this->replacer->replace($product, $filename, Product::IMAGE_SOURCE_AI);
                    $generated = true;
                    array_splice($pending, $index, 1);
                } catch (DishPhotoException $e) {
                    if ($e->isTrialOver()) {
                        // Spent elsewhere (the editor) meanwhile: the trial run is over.
                        $trialEnded = true;
                    } elseif ($e->isProviderDown() || in_array($e->reason, [DishPhotoException::REASON_RATE_LIMITED, DishPhotoException::REASON_MONTHLY_LIMIT], true)) {
                        $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_PAUSED);
                        $stopped = $e->reason;
                    } else {
                        $batch->addAiPhotosSkipped((int) $product->getId());
                        array_splice($pending, $index, 1);
                    }
                }
                if ($trial && $pending !== [] && $this->quota->trialRemaining($restaurant) === 0) {
                    $trialEnded = true;
                }
            }

            if ($trialEnded && $pending !== []) {
                $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_TRIAL);
            }
            $done = $stopped === null && ($pending === [] || $trialEnded);
            if ($done && !$trialEnded) {
                $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_DONE);
            }
            $this->em->flush();

            return [
                'done' => $done,
                'generated' => $generated,
                'remaining' => count($pending),
                'skipped' => count($batch->getAiPhotosSkipped()),
                'stopped' => $stopped,
                'trialEnded' => $trialEnded && $pending !== [],
            ];
        });
    }

    /**
     * The import's complete page on the free trial, dishes left without a
     * photo and nothing started yet: remember it, so that once the
     * restaurant pays Carta offers the rest. Never overrides a status.
     */
    public function markTrial(MenuImportBatch $batch): void
    {
        if ($batch->getAiPhotosStatus() === null && DishPhotoQuota::isLimited($batch->getRestaurant())) {
            $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_TRIAL);
            $this->em->flush();
        }
    }

    /**
     * A random dish, from a category that hasn't got one of this import's
     * AI photos yet when there is one.
     *
     * @param Product[] $pending non-empty
     * @param int[] $usedCategoryIds
     */
    public static function pickForTrial(array $pending, array $usedCategoryIds, ?Randomizer $randomizer = null): int
    {
        $fresh = array_keys(array_filter($pending, fn (Product $p) => !in_array($p->getCategory()->getId(), $usedCategoryIds, true)));
        $candidates = $fresh !== [] ? $fresh : array_keys($pending);

        return $candidates[($randomizer ?? new Randomizer())->getInt(0, count($candidates) - 1)];
    }

    /** @return int[] categories where this import already has an AI photo (the trial's earlier sample) */
    private function categoriesWithAiPhoto(MenuImportBatch $batch): array
    {
        return array_map('intval', $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(p.category)')
            ->from(Product::class, 'p')
            ->where('p.importBatch = :batch')
            ->andWhere('p.imageSource = :ai')
            ->setParameter('batch', $batch)
            ->setParameter('ai', Product::IMAGE_SOURCE_AI)
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function dismiss(MenuImportBatch $batch): void
    {
        $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_DISMISSED);
        $this->em->flush();
    }

    /**
     * Carta's notices for one import's AI photos, each with its kind:
     * - 'resume': started (running/paused) and left with dishes —
     *   "Fotos con IA a medias", "Continuar" (on the trial only while it
     *   has photos left);
     * - 'imported': left by the trial (AI_PHOTOS_TRIAL), once the restaurant
     *   pays — "N platos sin foto de tu carta importada".
     *
     * @return array<array{batch: MenuImportBatch, remaining: int, kind: string}>
     */
    public function noticesFor(Restaurant $restaurant): array
    {
        // No image key: no notice in Carta either.
        if (!$this->availability->isAvailable()) {
            return [];
        }

        $trial = DishPhotoQuota::isLimited($restaurant);
        if ($trial && $this->quota->trialRemaining($restaurant) === 0) {
            return [];
        }
        $statuses = [MenuImportBatch::AI_PHOTOS_RUNNING, MenuImportBatch::AI_PHOTOS_PAUSED];
        if (!$trial) {
            $statuses[] = MenuImportBatch::AI_PHOTOS_TRIAL;
        }

        $batches = $this->em->getRepository(MenuImportBatch::class)->findBy(
            ['restaurant' => $restaurant, 'aiPhotosStatus' => $statuses],
            ['id' => 'ASC'],
        );
        $notices = [];
        foreach ($batches as $batch) {
            $remaining = $this->pendingCount($batch);
            if ($remaining > 0) {
                $notices[] = [
                    'batch' => $batch,
                    'remaining' => $remaining,
                    'kind' => $batch->getAiPhotosStatus() === MenuImportBatch::AI_PHOTOS_TRIAL ? 'imported' : 'resume',
                ];
            }
        }

        return $notices;
    }

    /** @return array{0: string, 1: ?string} name and description in the menu's main language (any language as a fallback) */
    private function texts(Product $product, Restaurant $restaurant): array
    {
        $translation = $product->getDisplayTranslation($restaurant->getDefaultLanguage());

        return [$translation?->getName() ?? '', $translation?->getDescription()];
    }
}
