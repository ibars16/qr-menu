<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Enum\MenuImportBatchStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an AI-imported dish or category stops being "pending
 * review", and an import becomes COMPLETED.
 *
 * - confirm(): the owner published it (toggle) or saved it from the editor
 *   — clears needsReview. Does not flush: the caller's own flush writes it.
 * - closeFinished(): after the caller's flush — every READY_FOR_REVIEW
 *   import of the restaurant with nothing left pending becomes COMPLETED.
 *   Deleting a pending row counts as reviewing it ("discarded"), so the
 *   delete actions call only this.
 */
final class MenuImportReview
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function confirm(Product|Category $item): void
    {
        if (!$item->isNeedsReview()) {
            return;
        }
        $item->setNeedsReview(false);
        if ($item instanceof Product) {
            $item->setAiConfidence(null);
        }
    }

    public function closeFinished(Restaurant $restaurant): void
    {
        $this->em->createQueryBuilder()
            ->update(MenuImportBatch::class, 'b')
            ->set('b.status', ':completed')
            ->set('b.updatedAt', ':now')
            ->where('b.restaurant = :restaurant')
            ->andWhere('b.status = :ready')
            ->andWhere('NOT EXISTS (SELECT p.id FROM ' . Product::class . ' p WHERE p.importBatch = b AND p.needsReview = true)')
            ->andWhere('NOT EXISTS (SELECT c.id FROM ' . Category::class . ' c WHERE c.importBatch = b AND c.needsReview = true)')
            ->setParameter('completed', MenuImportBatchStatus::COMPLETED)
            ->setParameter('ready', MenuImportBatchStatus::READY_FOR_REVIEW)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('restaurant', $restaurant)
            ->getQuery()
            ->execute();
    }
}
