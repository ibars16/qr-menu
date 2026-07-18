<?php

namespace App\Service;

use App\Entity\Category;
use App\Enum\CategoryType;

/**
 * Decides whether the public menu's food/drink filter should render at
 * all, and whether a given category matches the customer's active
 * selection. See menu/_search_js.html.twig for the client-side mirror of
 * matchesFilter()'s rule (the browser can't call PHP, so the same logic is
 * duplicated there — keep the two in sync if this ever changes).
 */
final class CategoryTypeFilterResolver
{
    /**
     * The filter only earns its place in the UI when it can actually split
     * something — a restaurant with only food (or only drink, or only
     * unclassified) categories has nothing to filter by.
     *
     * @param Category[] $categories
     */
    public function shouldShowFilter(array $categories): bool
    {
        $hasFood  = false;
        $hasDrink = false;
        foreach ($categories as $category) {
            $hasFood  = $hasFood || $category->getType() === CategoryType::Food;
            $hasDrink = $hasDrink || $category->getType() === CategoryType::Drink;
        }

        return $hasFood && $hasDrink;
    }

    /**
     * An unclassified category (null type) always matches, in either
     * filter mode — only a category with an explicit, opposite type is
     * ever hidden.
     */
    public function matchesFilter(?CategoryType $categoryType, string $activeFilter): bool
    {
        if ($activeFilter === 'all' || $categoryType === null) {
            return true;
        }

        return $categoryType->value === $activeFilter;
    }
}
