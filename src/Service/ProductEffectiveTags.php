<?php

namespace App\Service;

use App\Entity\ProductTag;
use App\Enum\AllergenPresence;

/**
 * The tags a dish really carries, as the public menu (dish detail, set
 * menus) and Smart Waiter show them: the three "contains-…" system tags
 * are never taken from what was saved — they come from the dish's
 * effective allergens (ProductAllergenResolver), so "Contiene gluten" can
 * never disagree with the allergen list:
 *
 * - a stored "contains-gluten" / "contains-dairy" / "contains-nuts"
 *   assignment is ignored, whatever it says;
 * - the restaurant's own system tag is added when the matching allergen's
 *   effective presence is "contains" — never for "may_contain" or
 *   "free_from";
 * - every other tag (system or the owner's own) is kept as saved.
 *
 * Only system tags are recognised (code + isSystem, see ProductTag): an
 * owner's own tag is a label like any other. The admin editor shows these
 * three pills locked, lit from the same allergens, and saveProduct()
 * ignores them.
 */
final class ProductEffectiveTags
{
    /** System tag code => allergen code it stands for. "contains-nuts" is tree nuts, not peanuts. */
    public const CONTAINS_TAGS = [
        'contains-dairy' => 'milk',
        'contains-nuts' => 'nuts',
        'contains-gluten' => 'gluten',
    ];

    public static function isAllergenTag(ProductTag $tag): bool
    {
        return $tag->isSystem() && isset(self::CONTAINS_TAGS[$tag->getCode()]);
    }

    /**
     * @param iterable<ProductTag> $storedTags     the dish's saved tags
     * @param array<string, string> $presenceByCode allergen code => effective presence value
     * @param iterable<ProductTag> $restaurantTags  every tag of the dish's restaurant
     *
     * @return list<ProductTag> ordered like the rest of the menu: position, then id
     */
    public static function compute(iterable $storedTags, array $presenceByCode, iterable $restaurantTags): array
    {
        $tags = [];
        foreach ($storedTags as $tag) {
            if (!self::isAllergenTag($tag)) {
                $tags[] = $tag;
            }
        }

        foreach ($restaurantTags as $tag) {
            if (self::isAllergenTag($tag)
                && ($presenceByCode[self::CONTAINS_TAGS[$tag->getCode()]] ?? null) === AllergenPresence::CONTAINS->value) {
                $tags[] = $tag;
            }
        }

        usort($tags, static fn (ProductTag $a, ProductTag $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return $tags;
    }

    /**
     * ProductAllergenResolver entries as compute()'s allergen code => presence.
     *
     * @param list<array{allergen: \App\Entity\Allergen, presence: AllergenPresence}> $entries
     *
     * @return array<string, string>
     */
    public static function presencesFromEntries(array $entries): array
    {
        $presences = [];
        foreach ($entries as $entry) {
            $presences[$entry['allergen']->getCode()] = $entry['presence']->value;
        }

        return $presences;
    }
}
