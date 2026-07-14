<?php

namespace App\Service\AI;

/**
 * Rule-based complexity scoring — no extra AI call, no per-language keyword
 * list to maintain. A message is "complex" if it's simply long, or if it
 * combines two or more of this restaurant's own allergen/dietary terms
 * (already known per-request from MenuContextBuilder, so this works in
 * whatever language the customer is typing without any translation work of
 * its own), or if it pairs one of those terms with what looks like a budget.
 * Everything else — single-fact lookups like "which dishes are vegetarian"
 * or "what's in the pizza" — stays on the fast tier.
 */
final class HeuristicChatComplexityClassifier implements ChatComplexityClassifierInterface
{
    private const LONG_MESSAGE_WORD_COUNT = 25;
    private const MULTI_CONSTRAINT_THRESHOLD = 2;

    public function classify(string $message, array $restaurantVocabulary): string
    {
        $wordCount = count(preg_split('/\s+/', trim($message), -1, PREG_SPLIT_NO_EMPTY));
        if ($wordCount > self::LONG_MESSAGE_WORD_COUNT) {
            return 'complex';
        }

        $haystack = mb_strtolower($message);
        $matches = 0;
        foreach ($restaurantVocabulary as $term) {
            $term = trim($term);
            if ($term !== '' && str_contains($haystack, mb_strtolower($term))) {
                $matches++;
            }
        }

        if ($matches >= self::MULTI_CONSTRAINT_THRESHOLD) {
            return 'complex';
        }

        if ($matches >= 1 && preg_match('/\d{1,4}/', $message) === 1) {
            return 'complex'; // a constraint + something that looks like a budget/quantity
        }

        return 'simple';
    }
}
