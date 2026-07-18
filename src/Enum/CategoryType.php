<?php

namespace App\Enum;

/**
 * Broad food-vs-drink classification for a Category, driving the public
 * menu's optional type filter (see menu/_search_js.html.twig) and letting
 * the Smart Waiter tell food from drink recommendations (see
 * MenuContextBuilder). Null on Category means "unclassified" — never
 * inferred automatically; the restaurant owner sets it explicitly in the
 * admin panel. AI-imported categories are always created unclassified (see
 * MenuImportAssembler) — classification is a manual, later step.
 */
enum CategoryType: string
{
    case Food = 'food';
    case Drink = 'drink';
}
