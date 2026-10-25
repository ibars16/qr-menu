<?php

namespace App\Service\AllergenBackfill;

use App\Entity\ClassificationLog;
use App\Enum\ClassificationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The allergen backfill's record of doubtful ingredients, kept in
 * classification_log (classification_type 'allergen_backfill'), one row
 * per ingredient — global or private:
 *   AUTO_APPLIED_UNION  doubtful, applied as the union of every opinion
 *                       (attributes.applied = what was written)
 *   PENDING_REVIEW      doubtful and nobody named any allergen (empty
 *                       union), or a suggestion on a YAML/hand-set
 *                       ingredient: nothing written
 *   AUTO_APPLIED / APPROVED / REJECTED  a pending one settled later
 * IngredientAllergenBackfill skips anything pending here, so the daily run
 * doesn't spend quota re-asking about it.
 *
 * One row per ingredient, not per allergen (label is null): the proposal
 * is reviewed as a whole — an empty one ("no allergens") included — and
 * the reasons belong to the ingredient. attributes:
 *   proposal   code => presence, the review pass's answer (what would be applied)
 *   first_pass code => presence, or null when unknown
 *   reasons    list of Spanish reasons, as in the review file
 *   applied    code => presence actually written (AUTO_APPLIED_UNION only)
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
    public function enqueue(array $subject, AllergenDecision $decision, ?array $firstPass): ClassificationLog
    {
        $log = $this->findPending($subject['kind'], $subject['id']);
        if ($log === null) {
            $log = new ClassificationLog();
            $log->setSubjectType(self::SUBJECT_TYPES[$subject['kind']]);
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

        return $log;
    }

    /**
     * A doubtful ingredient applied as the union (AllergenDecision::APPLIED_UNION):
     * its pending row, if it had one, or a new row — kept as the record of
     * why it carries what it carries.
     *
     * @param array<string, mixed>       $subject
     * @param array<string, string>|null $firstPass
     * @param array<string, string>|null $reviewPass
     */
    public function recordAppliedUnion(array $subject, AllergenDecision $decision, ?array $firstPass, ?array $reviewPass): void
    {
        $log = $this->enqueue($subject, new AllergenDecision(AllergenDecision::REVIEW, $reviewPass ?? [], $decision->reasons), $firstPass);
        $attributes = $log->getAttributes();
        $attributes['outcome'] = AllergenDecision::APPLIED_UNION;
        $attributes['applied'] = (object) $decision->allergens;
        $log->setAttributes($attributes);
        $log->setStatus(ClassificationStatus::AUTO_APPLIED_UNION);
    }

    /**
     * Pending doubtful ones (not suggestions), oldest first.
     *
     * @return list<ClassificationLog>
     */
    public function findPendingReviews(): array
    {
        return array_values(array_filter(
            $this->em->getRepository(ClassificationLog::class)->findBy(
                ['classificationType' => self::CLASSIFICATION_TYPE, 'status' => ClassificationStatus::PENDING_REVIEW],
                ['id' => 'ASC'],
            ),
            static fn (ClassificationLog $log) => ($log->getAttributes()['outcome'] ?? null) === AllergenDecision::REVIEW,
        ));
    }

    /** @return string IngredientAllergenBackfill::KIND_* for a classification_log subject_type */
    public static function kindOf(string $subjectType): string
    {
        return array_search($subjectType, self::SUBJECT_TYPES, true);
    }

    /**
     * The ingredient was applied after all (a later run, current rules): out of the queue, kept as history.
     *
     * @param array<string, mixed> $subject
     */
    public function resolveApplied(array $subject): void
    {
        $this->findPending($subject['kind'], $subject['id'])?->setStatus(ClassificationStatus::AUTO_APPLIED);
    }

    /**
     * A person saved the ingredient's allergens (admin): that settles it.
     * APPROVED when they kept exactly the AI proposal, REJECTED otherwise;
     * what they saved goes into attributes.human_result.
     *
     * @param array<string, string> $allergens code => presence, as saved
     */
    public function resolveByHuman(string $kind, int $id, array $allergens): void
    {
        $log = $this->findPending($kind, $id);
        if ($log === null) {
            return;
        }
        ksort($allergens);
        $attributes = $log->getAttributes();
        $proposal = (array) ($attributes['proposal'] ?? []);
        ksort($proposal);
        $attributes['human_result'] = (object) $allergens;
        $log->setAttributes($attributes);
        $log->setStatus($proposal === $allergens ? ClassificationStatus::APPROVED : ClassificationStatus::REJECTED);
    }

    private function findPending(string $kind, int $id): ?ClassificationLog
    {
        return $this->em->getRepository(ClassificationLog::class)->findOneBy([
            'subjectType' => self::SUBJECT_TYPES[$kind],
            'subjectId' => $id,
            'classificationType' => self::CLASSIFICATION_TYPE,
            'status' => ClassificationStatus::PENDING_REVIEW,
        ]);
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
