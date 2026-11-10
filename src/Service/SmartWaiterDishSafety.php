<?php

namespace App\Service;

use App\Entity\Allergen;
use App\Entity\ProductTag;
use App\Enum\AllergenPresence;

/**
 * The diet labels and allergens of one dish exactly as Smart Waiter may
 * talk about them — nothing for the model to infer:
 *
 * - "labels": every tag's name ("recommended" excluded — that is
 *   MenuContextBuilder's `highlighted`), or "none" when there isn't one, so
 *   a dish without a diet label says so instead of leaving a gap the model
 *   might fill from the dish's name or ingredients.
 * - "allergens": grouped contains / may_contain / free_from (empty groups
 *   left out), or "?" when the restaurant gave none — never anything that
 *   reads as "no allergens", and no phrase the model could quote to the
 *   customer; notes separately in "allergen_notes",
 *   text untouched.
 * - A "contains-…" system tag (CONTAINS_TAGS) is an allergen fact, so it is
 *   added to "allergens" as contains — it only ever raises a presence
 *   (may_contain or even an owner's free_from becomes contains), never
 *   lowers one — instead of staying a label the allergen rules wouldn't read.
 * - A diet system tag that the allergens contradict (CONFLICTS) is left
 *   out: e.g. "vegan" on a dish that contains milk is never stated.
 *
 * Only system tags are recognised by code; an owner's own tag is a label
 * by its name, as written.
 *
 * FIT_KEY ("_fit") carries the same facts as codes — allergen code =>
 * presence (+ its name), and the system diet tags kept as labels — for
 * SmartWaiterDishFit to sort the dishes for one customer. Internal only:
 * SmartWaiterDishFit::apply() always removes it before anything reaches
 * the AI.
 */
final class SmartWaiterDishSafety
{
    /** System tag code => allergen code it means "contains". "contains-nuts" is tree nuts, not peanuts. */
    public const CONTAINS_TAGS = [
        'contains-dairy' => 'milk',
        'contains-nuts' => 'nuts',
        'contains-gluten' => 'gluten',
    ];

    /** Diet system tag code => [allergen code => presences that contradict it]. */
    public const CONFLICTS = [
        'vegan' => [
            'milk' => ['contains'], 'eggs' => ['contains'], 'fish' => ['contains'],
            'crustaceans' => ['contains'], 'molluscs' => ['contains'],
        ],
        'vegetarian' => [
            'fish' => ['contains'], 'crustaceans' => ['contains'], 'molluscs' => ['contains'],
        ],
        'gluten-free' => [
            'gluten' => ['contains', 'may_contain'],
        ],
    ];

    public const FIT_KEY = '_fit';

    public const NO_LABELS = 'none';
    // Never "none", an empty list or a missing key (all readable as "no
    // allergens"), and no phrase either (models quoted the old "unknown (not
    // specified by the restaurant)" to customers). Rule 6 explains it.
    public const NO_ALLERGENS = '?';

    /**
     * @param ProductTag[] $tags the dish's tags, "recommended" already taken out
     * @param list<array{allergen: Allergen, presence: AllergenPresence, note: ?string}> $allergenEntries
     *   ProductAllergenResolver's entries for the dish
     * @param \Closure(ProductTag): string $tagName
     * @param \Closure(Allergen): string $allergenName
     * @param \Closure(): array<string, Allergen> $allergensByCode only called when a
     *   "contains-…" tag names an allergen the dish has no entry for
     *
     * @return array{labels: list<string>|string, allergens: array<string, list<string>>|string, allergen_notes?: list<array{allergen: string, note: string}>, _fit: array{allergens: array<string, array{presence: string, name: string}>, diets: list<string>}}
     */
    public function shape(array $tags, array $allergenEntries, \Closure $tagName, \Closure $allergenName, \Closure $allergensByCode): array
    {
        /** @var array<string, array{allergen: Allergen, presence: string, note: ?string}> $byCode */
        $byCode = [];
        foreach ($allergenEntries as $entry) {
            $byCode[$entry['allergen']->getCode()] = [
                'allergen' => $entry['allergen'],
                'presence' => $entry['presence']->value,
                'note' => $entry['note'],
            ];
        }

        $labelTags = [];
        foreach ($tags as $tag) {
            $allergenCode = $tag->isSystem() ? (self::CONTAINS_TAGS[$tag->getCode()] ?? null) : null;
            if ($allergenCode === null) {
                $labelTags[] = $tag;
                continue;
            }
            if (isset($byCode[$allergenCode])) {
                $byCode[$allergenCode]['presence'] = AllergenPresence::CONTAINS->value;
                continue;
            }
            $allergen = $allergensByCode()[$allergenCode] ?? null;
            if ($allergen === null) {
                $labelTags[] = $tag; // unknown allergen: at least keep what the tag says
                continue;
            }
            $byCode[$allergenCode] = ['allergen' => $allergen, 'presence' => AllergenPresence::CONTAINS->value, 'note' => null];
        }

        $labels = [];
        $dietCodes = [];
        foreach ($labelTags as $tag) {
            if ($tag->isSystem() && $this->isContradicted($tag->getCode(), $byCode)) {
                continue;
            }
            $labels[] = $tagName($tag);
            if ($tag->isSystem()) {
                $dietCodes[] = $tag->getCode();
            }
        }

        $entries = array_values($byCode);
        usort($entries, static fn (array $a, array $b) => $a['allergen']->getPosition() <=> $b['allergen']->getPosition());

        $groups = [];
        $notes = [];
        $fitAllergens = [];
        foreach ($entries as $entry) {
            $name = $allergenName($entry['allergen']);
            $groups[$entry['presence']][] = $name;
            $fitAllergens[$entry['allergen']->getCode()] = ['presence' => $entry['presence'], 'name' => $name];
            if ($entry['note'] !== null && trim($entry['note']) !== '') {
                $notes[] = ['allergen' => $name, 'note' => $entry['note']];
            }
        }
        $allergens = [];
        foreach ([AllergenPresence::CONTAINS, AllergenPresence::MAY_CONTAIN, AllergenPresence::FREE_FROM] as $presence) {
            if (isset($groups[$presence->value])) {
                $allergens[$presence->value] = $groups[$presence->value];
            }
        }

        $result = [
            'labels' => $labels === [] ? self::NO_LABELS : $labels,
            'allergens' => $allergens === [] ? self::NO_ALLERGENS : $allergens,
        ];
        if ($notes !== []) {
            $result['allergen_notes'] = $notes;
        }
        $result[self::FIT_KEY] = ['allergens' => $fitAllergens, 'diets' => $dietCodes];

        return $result;
    }

    /** @param array<string, array{presence: string}> $byCode */
    private function isContradicted(string $tagCode, array $byCode): bool
    {
        foreach (self::CONFLICTS[$tagCode] ?? [] as $allergenCode => $presences) {
            if (isset($byCode[$allergenCode]) && in_array($byCode[$allergenCode]['presence'], $presences, true)) {
                return true;
            }
        }

        return false;
    }
}
