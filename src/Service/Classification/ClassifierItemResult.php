<?php

namespace App\Service\Classification;

/**
 * One subject's raw classification result from an AiClassifierProviderInterface,
 * before task-specific validation.
 */
final class ClassifierItemResult
{
    /**
     * @param list<array{label: string, confidence: float, attributes: array<string, mixed>}> $labels
     * @param float|null $noLabelConfidence how confident the model is that NO label applies —
     *        only meaningful when $labels is empty. Null/low means "unsure", not "confidently none".
     */
    public function __construct(
        public readonly array $labels,
        public readonly ?float $noLabelConfidence,
    ) {
    }
}
