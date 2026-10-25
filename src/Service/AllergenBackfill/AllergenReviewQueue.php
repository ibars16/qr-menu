<?php

namespace App\Service\AllergenBackfill;

use App\Entity\ClassificationLog;
use App\Enum\ClassificationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The allergen backfill's pending-review queue, kept in classification_log
 * (classification_type 'allergen_backfill'): one PENDING_REVIEW row per
 * ingredient — global or private — whose AI proposal needs a human look.
 * IngredientAllergenBackfill skips anything queued here, so the daily run
 * doesn't spend quota re-asking about it; the admin review screen reads
 * the same rows.
 *
 * One row per ingredient, not per allergen (label is null): the proposal
 * is reviewed as a whole — an empty one ("no allergens") included — and
 * the reasons belong to the ingredient. attributes:
 *   proposal   code => presence, the review pass's answer (what would be applied)
 *   first_pass code => presence, or null when unknown
 *   reasons    list of Spanish reasons, as in the review file
 *   outcome    'review' | 'suggestion' (YAML/hand-set ingredient: never applied automatically)
 *   name, locale, restaurant (slug, private only)
 * created_at is when it was queued; queuing it again updates the row.
 */
final class AllergenReviewQueue
{
    public const CLASSIFICATION_TYPE = 'allergen_backfill';

    /** classification_log.subject_type per IngredientAllergenBackfill kind */
    public const SUBJECT_TYPES = [
        IngredientAllergenBackfill::KIND_GLOBAL => 'global_ingredient',
        IngredientAllergenBackfill::KIND_PRIVATE => 'ingredient',
    ];

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param array<string, mixed>       $subject from IngredientAllergenBackfill
     * @param array<string, string>|null $firstPass
     */
    public function enqueue(array $subject, AllergenDecision $decision, ?array $firstPass): void
    {
        $subjectType = self::SUBJECT_TYPES[$subject['kind']];
        $log = $this->em->getRepository(ClassificationLog::class)->findOneBy([
            'subjectType' => $subjectType,
            'subjectId' => $subject['id'],
            'classificationType' => self::CLASSIFICATION_TYPE,
            'status' => ClassificationStatus::PENDING_REVIEW,
        ]);
        if ($log === null) {
            $log = new ClassificationLog();
            $log->setSubjectType($subjectType);
            $log->setSubjectId($subject['id']);
            $log->setClassificationType(self::CLASSIFICATION_TYPE);
            $log->setStatus(ClassificationStatus::PENDING_REVIEW);
            $log->setSource('ai');
            $this->em->persist($log);
        }
        $log->setAttributes([
            'proposal' => (object) $decision->allergens,
            'first_pass' => $firstPass !== null ? (object) $firstPass : null,
            'reasons' => $decision->reasons,
            'outcome' => $decision->outcome,
            'name' => $subject['name'],
            'locale' => $subject['locale'],
            'restaurant' => $subject['restaurant'],
        ]);
        $log->setReviewNote(mb_substr(implode(' · ', $decision->reasons), 0, 500));
    }

    /**
     * The ingredient was applied after all (a later run, current rules): out of the queue, kept as history.
     *
     * @param array<string, mixed> $subject
     */
    public function resolveApplied(array $subject): void
    {
        $log = $this->em->getRepository(ClassificationLog::class)->findOneBy([
            'subjectType' => self::SUBJECT_TYPES[$subject['kind']],
            'subjectId' => $subject['id'],
            'classificationType' => self::CLASSIFICATION_TYPE,
            'status' => ClassificationStatus::PENDING_REVIEW,
        ]);
        $log?->setStatus(ClassificationStatus::AUTO_APPLIED);
    }

    /** @return array<int, true> ingredient id => true, for every one of this kind waiting in the queue */
    public function pendingIds(string $kind): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT l.subjectId')
            ->from(ClassificationLog::class, 'l')
            ->where('l.classificationType = :type AND l.subjectType = :subjectType AND l.status = :status')
            ->setParameter('type', self::CLASSIFICATION_TYPE)
            ->setParameter('subjectType', self::SUBJECT_TYPES[$kind])
            ->setParameter('status', ClassificationStatus::PENDING_REVIEW)
            ->getQuery()->getScalarResult();

        return array_fill_keys(array_map(static fn (array $r) => (int) $r['subjectId'], $rows), true);
    }
}
