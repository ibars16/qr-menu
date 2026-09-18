<?php

namespace App\Twig;

use App\Service\MenuNumberFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MenuNumberExtension extends AbstractExtension
{
    public function __construct(private readonly MenuNumberFormatter $menuNumberFormatter)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('menu_number', [$this, 'format']),
        ];
    }

    public function format(float $amount, string $locale): string
    {
        return $this->menuNumberFormatter->format($amount, $locale);
    }
}
