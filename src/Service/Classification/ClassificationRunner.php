<?php

namespace App\Service\Classification;

use App\Entity\ClassificationLog;
use App\Enum\ClassificationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generic orchestration for any ClassificationTaskInterface: fetch
 * unclassified subjects, ask the AI provider to classify them in batches,
 * and for every proposal either apply it immediately (confidence at or
 * above the threshold) or park it in the review queue — logging every
 * outcome, including "genuinely uncertain" (nothing logged at all, so the
 * subject stays eligible for a future run) and "confidently nothing
 * applies" (a sentinel row, so it isn't re-asked forever).
 *
 * This class knows nothing about allergens, ingredients, or any other
 * domain concept — that's entirely behind ClassificationTaskInterface.
 */
final class ClassificationRunner
{
    public function __construct(
        private readonly AiClassifierProviderInterface $provider,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return array{totalSubjects: int, autoApplied: int, pendingReview: int, noLabelsFound: int, uncertainSkipped: int, discardedInvalid: int, batchesFailed: int}
     */
    public function run(
        ClassificationTaskInterface $task,
        int $limit,
        int $batchSize,
        float $threshold,
        bool $reviewOnly,
        ?callable $onBatchComplete = null,
    ): array {
        $subjects = $task->findUnclassified($limit);

        $counts = [
            'totalSubjects'    => count($subjects),
            'autoApplied'      => 0,
            'pendingReview'    => 0,
            'noLabelsFound'    => 0,
            'uncertainSkipped' => 0,
            'discardedInvalid' => 0,
            'batchesFailed'    => 0,
        ];

        foreach (array_chunk($subjects, max(1, $batchSize), true) as $batch) {
            $items = [];
            foreach ($batch as $id => $subject) {
                $items[$id] = $task->getSubjectDisplayText($subject);
            }

            try {
                $results = $this->provider->classify($items, $task->getInstructions(), $task->getLabelVocabulary());
            } catch (\Throwable $e) {
                // A failed batch must not abort the run, and must not log
                // anything for its subjects — they simply remain eligible
                // for the next classify:run.
                $counts['batchesFailed']++;
                if ($onBatchComplete) {
                    $onBatchComplete(count($batch), $e);
                }
                continue;
            }

            foreach ($batch as $id => $subject) {
                $this->processSubject($task, $id, $subject, $results[$id] ?? null, $threshold, $reviewOnly, $counts);
            }

            $this->em->flush();
            if ($onBatchComplete) {
                $onBatchComplete(count($batch), null);
            }
        }

        return $counts;
    }

    private function processSubject(
        ClassificationTaskInterface $task,
        int $subjectId,
        object $subject,
        ?ClassifierItemResult $result,
        float $threshold,
        bool $reviewOnly,
        array &$counts,
    ): void {
        // Missing from the response entirely — treated identically to
        // genuine uncertainty: no ClassificationLog row, so it's simply
        // retried on the next run rather than being marked as anything.
        if ($result === null) {
            $counts['uncertainSkipped']++;
            return;
        }

        if (empty($result->labels)) {
            if ($result->noLabelConfidence !== null && $result->noLabelConfidence >= $threshold) {
                $this->persistLog($task, $subjectId, null, null, $result->noLabelConfidence, ClassificationStatus::NO_LABELS_FOUND);
                $counts['noLabelsFound']++;
            } else {
                $counts['uncertainSkipped']++;
            }
            return;
        }

        foreach ($result->labels as $labelData) {
            $normalized = $task->validateProposal($subject, $labelData['label'], $labelData['attributes']);
            if ($normalized === null) {
                $counts['discardedInvalid']++;
                continue;
            }

            $confidence = $labelData['confidence'];

            if (!$reviewOnly && $confidence >= $threshold) {
                $task->apply($subject, $labelData['label'], $normalized);
                $this->persistLog($task, $subjectId, $labelData['label'], $normalized, $confidence, ClassificationStatus::AUTO_APPLIED);
                $counts['autoApplied']++;
            } else {
                $this->persistLog($task, $subjectId, $labelData['label'], $normalized, $confidence, ClassificationStatus::PENDING_REVIEW);
                $counts['pendingReview']++;
            }
        }
    }

    private function persistLog(
        ClassificationTaskInterface $task,
        int $subjectId,
        ?string $label,
        ?array $attributes,
        ?float $confidence,
        ClassificationStatus $status,
    ): void {
        $log = new ClassificationLog();
        $log->setSubjectType($task->getSubjectType());
        $log->setSubjectId($subjectId);
        $log->setClassificationType($task->getName());
        $log->setLabel($label);
        $log->setAttributes($attributes);
        $log->setConfidence($confidence);
        $log->setStatus($status);
        $log->setSource('ai');

        $this->em->persist($log);
    }
}
