<?php

namespace App\Enum;

/**
 * Why a dish is not on the public menu — see Product::menuHiddenReason().
 * The backing values are stable identifiers (admin translation keys hang
 * off them), so never rename one without migrating whatever uses it.
 */
enum MenuHiddenReason: string
{
    /** The dish's own category is hidden ($active = false). */
    case CategoryHidden = 'category_hidden';

    /** The dish itself is hidden ($active = false) — the owner did this on purpose. */
    case ProductHidden = 'product_hidden';

    /** A normal dish priced at €0 — see Product::isSafeToDisplay(). */
    case PriceZero = 'price_zero';

    /** The dish (see Product::hasMenuName()) or its category has no name to render. The value keeps its historical name. */
    case NoTranslation = 'no_translation';
}
