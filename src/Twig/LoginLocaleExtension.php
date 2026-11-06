<?php

namespace App\Twig;

use App\Service\AdminLocaleResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * login_locales(): the languages of the logged-out screens' picker
 * (security/_top_actions.html.twig), by code: de, en, es, fr, it, pt.
 */
class LoginLocaleExtension extends AbstractExtension
{
    public function __construct(private readonly AdminLocaleResolver $localeResolver)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('login_locales', $this->loginLocales(...))];
    }

    /** @return array<string, array{name: string, flag: string}> */
    public function loginLocales(): array
    {
        $locales = $this->localeResolver->getLocales();
        ksort($locales);

        return $locales;
    }
}
