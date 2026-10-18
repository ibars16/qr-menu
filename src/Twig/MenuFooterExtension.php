<?php

namespace App\Twig;

use App\Service\OpeningHoursGrouper;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Helpers for the public menu footer (menu/_footer.html.twig). */
class MenuFooterExtension extends AbstractExtension
{
    public function __construct(private readonly OpeningHoursGrouper $openingHoursGrouper)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('opening_hours_groups', [$this->openingHoursGrouper, 'group']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('tel_href', [$this, 'telHref']),
        ];
    }

    /** "+34 600 12-34-56" → "tel:+34600123456": digits only, keeping a leading "+". */
    public function telHref(string $phone): string
    {
        $phone = trim($phone);

        return 'tel:' . (str_starts_with($phone, '+') ? '+' : '') . preg_replace('/\D/', '', $phone);
    }
}
