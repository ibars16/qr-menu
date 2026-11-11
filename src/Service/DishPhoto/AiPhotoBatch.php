<?php

namespace App\Service\DishPhoto;

use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Service\Upload\ProductImageReplacer;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Crear sus fotos con IA" after an import: the dishes of THAT import with
 * no photo, one per request — the browser calls step() until done, like
 * the main-language change (no queue, no cron). Closing the window leaves
 * aiPhotosStatus 'running' and Carta offers "Continuar".
 *
 * Per dish: refused / timed out → skipped for good (aiPhotosSkipped), next
 * dish; provider down (429/5xx/network/no key) or the brake → 'paused',
 * "Reintentar"; trial limit → 'paused' with the choose-a-plan message.
 */
class AiPhotoBatch
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DishPhotoGenerator $generator,
        private readonly ProductImageReplacer $replacer,
        private readonly DishPhotoAvailability $availability,
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
     * @return array{done: bool, generated: bool, remaining: int, skipped: int, stopped: ?string}
     *   stopped: null, or a DishPhotoException reason that paused the batch
     */
    public function step(MenuImportBatch $batch): array
    {
        return $this->em->wrapInTransaction(function () use ($batch): array {
            $this->em->lock($batch, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($batch);

            if ($batch->getAiPhotosStatus() !== MenuImportBatch::AI_PHOTOS_RUNNING) {
                $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_RUNNING);
            }

            $pending = $this->pending($batch);
            $generated = false;
            $stopped = null;

            if ($pending !== []) {
                $product = $pending[0];
                [$name, $description] = $this->texts($product, $batch->getRestaurant());
                try {
                    if (trim($name) === '') {
                        throw new DishPhotoException(DishImageFailure::Refused->value);
                    }
                    $filename = $this->generator->forProduct($product, $name, $description);
                    $this->replacer->replace($product, $filename, Product::IMAGE_SOURCE_AI);
                    $generated = true;
                    array_shift($pending);
                } catch (DishPhotoException $e) {
                    if ($e->isProviderDown() || in_array($e->reason, [DishPhotoException::REASON_TRIAL_LIMIT, DishPhotoException::REASON_RATE_LIMITED], true)) {
                        $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_PAUSED);
                        $stopped = $e->reason;
                    } else {
                        $batch->addAiPhotosSkipped((int) $product->getId());
                        array_shift($pending);
                    }
                }
            }

            $done = $stopped === null && $pending === [];
            if ($done) {
                $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_DONE);
            }
            $this->em->flush();

            return [
                'done' => $done,
                'generated' => $generated,
                'remaining' => count($pending),
                'skipped' => count($batch->getAiPhotosSkipped()),
                'stopped' => $stopped,
            ];
        });
    }

    public function dismiss(MenuImportBatch $batch): void
    {
        $batch->setAiPhotosStatus(MenuImportBatch::AI_PHOTOS_DISMISSED);
        $this->em->flush();
    }

    /**
     * Carta's "Fotos con IA a medias" notices: started (running/paused)
     * imports with dishes still left.
     *
     * @return array<array{batch: MenuImportBatch, remaining: int}>
     */
    public function noticesFor(Restaurant $restaurant): array
    {
        // No image key: no "Continuar" notice in Carta either.
        if (!$this->availability->isAvailable()) {
            return [];
        }

        $batches = $this->em->getRepository(MenuImportBatch::class)->findBy(
            ['restaurant' => $restaurant, 'aiPhotosStatus' => [MenuImportBatch::AI_PHOTOS_RUNNING, MenuImportBatch::AI_PHOTOS_PAUSED]],
            ['id' => 'ASC'],
        );
        $notices = [];
        foreach ($batches as $batch) {
            $remaining = $this->pendingCount($batch);
            if ($remaining > 0) {
                $notices[] = ['batch' => $batch, 'remaining' => $remaining];
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
