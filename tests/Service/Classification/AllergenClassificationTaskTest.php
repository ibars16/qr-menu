<?php

namespace App\Tests\Service\Classification;

use App\Entity\ClassificationLog;
use App\Entity\GlobalIngredient;
use App\Enum\ClassificationStatus;
use App\Service\AllergenBackfill\AllergenReviewQueue;
use App\Service\Classification\Task\AllergenClassificationTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The old Gemini allergen task must leave alone what the allergen backfill
 * has already settled: ingredients reviewed (also "reviewed, no allergens")
 * and ingredients waiting in its review queue.
 */
final class AllergenClassificationTaskTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    /** @var list<int> */
    private array $ids = [];

    protected function tearDown(): void
    {
        if ($this->em !== null && $this->ids !== []) {
            $this->em->clear();
            $this->em->createQuery('DELETE FROM ' . ClassificationLog::class . ' l WHERE l.subjectType = :s AND l.subjectId IN (:ids)')
                ->execute(['s' => 'global_ingredient', 'ids' => $this->ids]);
            foreach ($this->ids as $id) {
                if ($ingredient = $this->em->find(GlobalIngredient::class, $id)) {
                    $this->em->remove($ingredient);
                }
            }
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testSkipsReviewedAndPendingIngredients(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $untouched = $this->ingredient();
        $reviewed = $this->ingredient();
        $reviewed->markAllergensReviewed('ai');
        $pending = $this->ingredient();
        $resolved = $this->ingredient();
        $this->em->flush();

        foreach ([[$pending, ClassificationStatus::PENDING_REVIEW], [$resolved, ClassificationStatus::REJECTED]] as [$ingredient, $status]) {
            $log = new ClassificationLog();
            $log->setSubjectType('global_ingredient');
            $log->setSubjectId($ingredient->getId());
            $log->setClassificationType(AllergenReviewQueue::CLASSIFICATION_TYPE);
            $log->setStatus($status);
            $this->em->persist($log);
        }
        $this->em->flush();

        $task = static::getContainer()->get(AllergenClassificationTask::class);
        $ids = array_keys($task->findUnclassified(1_000_000));

        self::assertContains($untouched->getId(), $ids);
        self::assertNotContains($reviewed->getId(), $ids, 'reviewed');
        self::assertNotContains($pending->getId(), $ids, 'waiting for review');
        self::assertContains($resolved->getId(), $ids, 'a settled queue row no longer blocks it (it is unreviewed again)');
        self::assertSame(count($ids), $task->countUnclassified());
    }

    private function ingredient(): GlobalIngredient
    {
        $ingredient = new GlobalIngredient();
        $ingredient->setCode('gemini-task-test-' . uniqid());
        $this->em->persist($ingredient);
        $this->em->flush();
        $this->ids[] = $ingredient->getId();

        return $ingredient;
    }
}
