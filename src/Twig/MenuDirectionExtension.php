<?php

namespace App\Twig;

use App\Service\MenuPreferencesResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** menu_dir(locale): 'rtl' or 'ltr' for the public menu (see MenuPreferencesResolver::getTextDirection()). */
class MenuDirectionExtension extends AbstractExtension
{
    public function __construct(private readonly MenuPreferencesResolver $menuPreferencesResolver)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('menu_dir', [$this->menuPreferencesResolver, 'getTextDirection']),
        ];
    }
}
