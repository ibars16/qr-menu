<?php

namespace App\Service\AI;

/**
 * Decides whether a customer's message needs a "fast" or "capable" model.
 * Deliberately an interface: today's implementation is a documented
 * heuristic (see HeuristicChatComplexityClassifier), and this is the seam
 * to swap in something smarter later without touching AIModelRouter.
 */
interface ChatComplexityClassifierInterface
{
    /**
     * @param string[] $restaurantVocabulary Allergen + dietary tag names for
     *        this restaurant in the customer's own locale — used as a free,
     *        restaurant-specific signal instead of a generic keyword list.
     */
    public function classify(string $message, array $restaurantVocabulary): string; // 'simple' | 'complex'
}
