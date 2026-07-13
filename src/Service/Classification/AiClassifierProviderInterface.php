<?php

namespace App\Service\Classification;

/**
 * Contract for an AI backend that classifies a batch of subjects against a
 * label vocabulary in one call. Deliberately provider-agnostic and
 * domain-agnostic — see App\Service\TagTranslatorInterface /
 * IngredientTranslatorInterface for the same pattern applied to
 * translation; this is the classification equivalent.
 */
interface AiClassifierProviderInterface
{
    /**
     * @param  array<int, string> $items           subjectId => text describing the subject
     * @param  string             $instructions    domain instructions (label meanings, attributes to include, examples)
     * @param  string[]           $labelVocabulary the only labels allowed, or [] for an open-ended label
     * @return array<int, ClassifierItemResult>     subjectId => result, only for ids actually present in $items
     */
    public function classify(array $items, string $instructions, array $labelVocabulary): array;
}
