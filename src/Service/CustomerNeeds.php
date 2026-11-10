<?php

namespace App\Service;

/**
 * What one Smart Waiter customer has said they must avoid, as codes —
 * found by SmartWaiterNeedsDetector, kept per conversation
 * (SmartWaiterConversationStore), and read by SmartWaiterDishFit to sort
 * every dish for them. Only ever grows within a conversation (merge()):
 * nothing the customer said is ever forgotten — the safe side.
 *
 * - avoid:   allergen codes from ALLERGENS (the 14 EU allergens);
 * - diets:   from DIETS;
 * - allergy: they said they, or someone they are ordering for, have an
 *            allergy or intolerance — what turns on the staff sentence;
 * - other:   they must avoid something these codes can't express (e.g.
 *            tomato, pork) — then no dish can be confirmed for them.
 */
final readonly class CustomerNeeds
{
    /** The 14 EU allergens, as the allergen table codes them. "nuts" = tree nuts. */
    public const ALLERGENS = ['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soybeans', 'milk', 'nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'];

    /** Diets a dish can be checked for: the system diet tags' codes. */
    public const DIETS = ['vegetarian', 'vegan', 'halal'];

    /** Plain English for the instruction's CHECKED FOR THIS CUSTOMER block, where a code alone would read oddly. */
    private const ALLERGEN_WORDS = ['nuts' => 'tree nuts', 'soybeans' => 'soy'];

    /**
     * @param list<string> $avoid codes from ALLERGENS
     * @param list<string> $diets codes from DIETS
     */
    public function __construct(
        public array $avoid = [],
        public array $diets = [],
        public bool $allergy = false,
        public bool $other = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->avoid === [] && $this->diets === [] && !$this->other;
    }

    /** Everything either one says, in the lists' own order — never less than before. */
    public function merge(self $more): self
    {
        return new self(
            self::ordered(self::ALLERGENS, [...$this->avoid, ...$more->avoid]),
            self::ordered(self::DIETS, [...$this->diets, ...$more->diets]),
            $this->allergy || $more->allergy,
            $this->other || $more->other,
        );
    }

    /** @return list<string> the avoided allergens in plain English (e.g. "tree nuts") */
    public function avoidWords(): array
    {
        return array_map(self::word(...), $this->avoid);
    }

    /** One allergen code in plain English (e.g. "nuts" → "tree nuts"). */
    public static function word(string $code): string
    {
        return self::ALLERGEN_WORDS[$code] ?? $code;
    }

    /** @return array{avoid: list<string>, diets: list<string>, allergy: bool, other: bool} */
    public function toArray(): array
    {
        return ['avoid' => $this->avoid, 'diets' => $this->diets, 'allergy' => $this->allergy, 'other' => $this->other];
    }

    /** From toArray() — tolerant: anything unknown is dropped, a missing part is empty. */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            return new self();
        }

        return new self(
            self::ordered(self::ALLERGENS, is_array($data['avoid'] ?? null) ? $data['avoid'] : []),
            self::ordered(self::DIETS, is_array($data['diets'] ?? null) ? $data['diets'] : []),
            ($data['allergy'] ?? false) === true,
            ($data['other'] ?? false) === true,
        );
    }

    /**
     * @param list<string> $known
     *
     * @return list<string> the known codes among $codes, once each, in $known's order
     */
    private static function ordered(array $known, array $codes): array
    {
        return array_values(array_intersect($known, $codes));
    }
}
