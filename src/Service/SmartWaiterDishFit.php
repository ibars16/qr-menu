<?php

namespace App\Service;

/**
 * Sorts every dish of the menu context for ONE customer (CustomerNeeds) —
 * deterministic, no AI — so the AI never has to cross an allergy with each
 * dish itself:
 *
 * - not_suitable: an allergen they avoid is "contains" or "may_contain",
 *   or the dish contradicts their diet (SmartWaiterDishSafety::CONFLICTS,
 *   e.g. vegan + contains milk). Sent as its name, "spicy": true (if it
 *   is) and why only — no price, no description, no "highlighted" — so it
 *   is recognised if asked by name, and the AI can say it exists without
 *   selling it ("our only spicy dish has gluten").
 * - suitable: every allergen they avoid is "free_from" (gluten: or the
 *   dish keeps its system "gluten-free" label), every diet is one of its
 *   system diet labels, and nothing they avoid is outside the codes
 *   (CustomerNeeds::$other).
 * - not_confirmed: everything else (e.g. "allergens": "?"), each dish with
 *   "unknown_for_customer": what the menu doesn't confirm for them (e.g.
 *   ["gluten"]) — so a list of other allergens can't be read as "no gluten";
 *   a bare "allergens": "?" is left out (it says nothing more).
 *
 * A category's "products" (a set menu section's "dishes") become
 * "suitable" / "not_confirmed" / "not_suitable", empty groups left out. With
 * no needs at all the shape stays exactly as before. Either way the
 * internal SmartWaiterDishSafety::FIT_KEY is removed from every dish:
 * apply() is what makes a menu context ready to be sent.
 */
final class SmartWaiterDishFit
{
    public const SUITABLE = 'suitable';
    public const NOT_CONFIRMED = 'not_confirmed';
    /** On each "not_confirmed" dish: what the menu doesn't confirm for this customer. */
    public const UNKNOWN_KEY = 'unknown_for_customer';
    /** In UNKNOWN_KEY when the customer avoids something the codes can't express (CustomerNeeds::$other). */
    public const OTHER_UNKNOWN = 'the other things they avoid';
    public const NOT_SUITABLE = 'not_suitable';

    /**
     * @param array<string, mixed> $context MenuContextBuilder::build()
     *
     * @return array<string, mixed> the same context, sorted for $needs, FIT_KEY gone
     */
    public function apply(array $context, CustomerNeeds $needs): array
    {
        foreach ($context['categories'] as $i => $category) {
            if (isset($category['products'])) {
                $context['categories'][$i] = $this->sortDishes($category, 'products', $needs);
            }
            foreach ($category['sections'] ?? [] as $j => $section) {
                $context['categories'][$i]['sections'][$j] = $this->sortDishes($section, 'dishes', $needs);
            }
        }

        return $context;
    }

    /** Whether an apply()'d context has at least one "suitable" dish anywhere — SmartWaiterStaffNote's choice of sentence. */
    public static function hasSuitable(array $sortedContext): bool
    {
        foreach ($sortedContext['categories'] as $category) {
            if (($category[self::SUITABLE] ?? []) !== []) {
                return true;
            }
            foreach ($category['sections'] ?? [] as $section) {
                if (($section[self::SUITABLE] ?? []) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * $holder[$key] replaced, in place (same key order), by the groups.
     *
     * @param array<string, mixed> $holder a category or a set menu section
     *
     * @return array<string, mixed>
     */
    private function sortDishes(array $holder, string $key, CustomerNeeds $needs): array
    {
        $groups = [self::SUITABLE => [], self::NOT_CONFIRMED => [], self::NOT_SUITABLE => []];
        $plain = [];
        foreach ($holder[$key] as $dish) {
            $fit = $dish[SmartWaiterDishSafety::FIT_KEY] ?? ['allergens' => [], 'diets' => []];
            unset($dish[SmartWaiterDishSafety::FIT_KEY]);
            if ($needs->isEmpty()) {
                $plain[] = $dish;
                continue;
            }

            $why = $this->whyNot($fit, $needs);
            if ($why !== []) {
                // "spicy": true, never the level: nothing quotable, just enough for "our only spicy dish has gluten".
                $groups[self::NOT_SUITABLE][] = ['name' => $dish['name']]
                    + (isset($dish['spicy_level']) ? ['spicy' => true] : [])
                    + $why;
                continue;
            }
            $unknown = $this->unknown($fit, $needs);
            if ($unknown === []) {
                $groups[self::SUITABLE][] = $dish;
            } else {
                // A bare "?" adds nothing to UNKNOWN_KEY (and gets quoted): left out.
                // Allergens the menu does list stay ("does the flan have milk?").
                if (($dish['allergens'] ?? null) === SmartWaiterDishSafety::NO_ALLERGENS) {
                    unset($dish['allergens']);
                }
                $groups[self::NOT_CONFIRMED][] = $dish + [self::UNKNOWN_KEY => $unknown];
            }
        }

        $sorted = [];
        foreach ($holder as $k => $value) {
            if ($k !== $key) {
                $sorted[$k] = $value;
                continue;
            }
            if ($needs->isEmpty()) {
                $sorted[$key] = $plain;
                continue;
            }
            foreach ($groups as $group => $dishes) {
                if ($dishes !== []) {
                    $sorted[$group] = $dishes;
                }
            }
        }

        return $sorted;
    }

    /**
     * @param array{allergens: array<string, array{presence: string, name: string}>, diets: list<string>} $fit
     *
     * @return array{contains?: list<string>, may_contain?: list<string>, not?: list<string>} empty when nothing rules it out
     */
    private function whyNot(array $fit, CustomerNeeds $needs): array
    {
        $why = [];
        foreach ($needs->avoid as $code) {
            $allergen = $fit['allergens'][$code] ?? null;
            if ($allergen !== null && in_array($allergen['presence'], ['contains', 'may_contain'], true)) {
                $why[$allergen['presence']][] = $allergen['name'];
            }
        }
        foreach ($needs->diets as $diet) {
            foreach (SmartWaiterDishSafety::CONFLICTS[$diet] ?? [] as $code => $presences) {
                if (in_array($fit['allergens'][$code]['presence'] ?? null, $presences, true)) {
                    $why['not'][] = $diet;
                    break;
                }
            }
        }

        // Always the same key order: contains, may_contain, not.
        return array_filter(['contains' => $why['contains'] ?? [], 'may_contain' => $why['may_contain'] ?? [], 'not' => $why['not'] ?? []]);
    }

    /**
     * What the menu doesn't confirm for this customer — empty: suitable.
     *
     * @param array{allergens: array<string, array{presence: string, name: string}>, diets: list<string>} $fit
     *
     * @return list<string> plain English (CustomerNeeds::word()), diets as their codes
     */
    private function unknown(array $fit, CustomerNeeds $needs): array
    {
        $unknown = [];
        foreach ($needs->avoid as $code) {
            $freeFrom = ($fit['allergens'][$code]['presence'] ?? null) === 'free_from';
            if (!$freeFrom && !($code === 'gluten' && in_array('gluten-free', $fit['diets'], true))) {
                $unknown[] = CustomerNeeds::word($code);
            }
        }
        foreach ($needs->diets as $diet) {
            if (!in_array($diet, $fit['diets'], true)) {
                $unknown[] = $diet;
            }
        }
        if ($needs->other) {
            $unknown[] = self::OTHER_UNKNOWN;
        }

        return $unknown;
    }
}
