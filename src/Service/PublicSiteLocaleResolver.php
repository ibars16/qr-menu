<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the language of the public marketing site (home, about, legal
 * pages). Entirely separate from AdminLocaleResolver (admin panel UI) and
 * MenuPreferencesResolver (per-visitor public menu language) — see
 * config/public_site_locales.php.
 */
class PublicSiteLocaleResolver
{
    private const DEFAULT_LOCALE = 'es';

    /** @var array<string, array{name: string, flag: string}> */
    private array $locales;

    public function __construct(string $projectDir)
    {
        $this->locales = require $projectDir . '/config/public_site_locales.php';
    }

    /** @return array<string, array{name: string, flag: string}> */
    public function getLocales(): array
    {
        return $this->locales;
    }

    /** @return string[] */
    public function getSupportedLocaleCodes(): array
    {
        return array_keys($this->locales);
    }

    public function getDefaultLocale(): string
    {
        return self::DEFAULT_LOCALE;
    }

    public function isSupported(string $locale): bool
    {
        return isset($this->locales[$locale]);
    }

    /**
     * ?lang= override > browser Accept-Language > default. No persistence
     * (cookie) — the site is a single page today, not worth the extra state.
     *
     * Deliberately checks headers->has() first: Request::getPreferredLanguage()
     * falls back to the *first* candidate in the list it's given (not null)
     * when there's no Accept-Language header at all, which would otherwise
     * silently mask the real default below.
     */
    public function resolveFromRequest(Request $request): string
    {
        $queryLang = $request->query->get('lang');
        if (is_string($queryLang) && $this->isSupported($queryLang)) {
            return $queryLang;
        }

        if ($request->headers->has('Accept-Language')) {
            $preferred = $request->getPreferredLanguage($this->getSupportedLocaleCodes());
            if ($preferred !== null && $this->isSupported($preferred)) {
                return $preferred;
            }
        }

        return $this->getDefaultLocale();
    }
}
