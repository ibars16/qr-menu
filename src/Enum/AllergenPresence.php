<?php

namespace App\Enum;

/**
 * How strongly an allergen is associated with an ingredient or a product.
 *
 * CONTAINS and MAY_CONTAIN can come from either an automatic computation
 * (an ingredient carries the allergen) or a manual product-level override
 * (e.g. shared-fryer cross-contamination). FREE_FROM is different in kind:
 * it is never computed, only ever set explicitly by a restaurant owner on a
 * ProductAllergenOverride — see ProductAllergenResolver.
 */
enum AllergenPresence: string
{
    case CONTAINS = 'contains';
    case MAY_CONTAIN = 'may_contain';
    case FREE_FROM = 'free_from';
}
