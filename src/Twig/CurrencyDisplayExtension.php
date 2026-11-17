<?php

namespace App\Twig;

use App\Service\MenuPreferencesResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ restaurant.currency|currency_display }}` — the same short label the
 * public menu puts next to a price (MenuPreferencesResolver::
 * getCurrencyDisplay(): "€" for EUR, the code when a symbol is shared).
 * Lets the admin panel print prices like the menu does, together with
 * |menu_number(app.request.locale), without each controller passing it.
 */
class CurrencyDisplayExtension extends AbstractExtension
{
    public function __construct(private readonly MenuPreferencesResolver $menuPreferencesResolver)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('currency_display', [$this->menuPreferencesResolver, 'getCurrencyDisplay']),
        ];
    }
}
