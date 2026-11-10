<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the language of the public marketing site (home, about, legal
 * pages). Entirely separate from AdminLocaleResolver (admin panel UI) and
 * MenuPreferencesResolver (per-visitor public menu language) — see
 * config/public_site_locales.php.
 */
class PublicSiteLocaleResolver
{
    private const DEFAULT_LOCALE = 'es';

    /**
     * The language picked in the site's selector, so it carries over from
     * the home to About and the legal pages (listed in the cookie policy,
     * templates/pages/cookies/*).
     */
    public const COOKIE = 'site_lang';

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
     * ?lang= override > the language picked before (COOKIE) > browser
     * Accept-Language > default.
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

        $cookieLang = $request->cookies->get(self::COOKIE);
        if (is_string($cookieLang) && $this->isSupported($cookieLang)) {
            return $cookieLang;
        }

        if ($request->headers->has('Accept-Language')) {
            $preferred = $request->getPreferredLanguage($this->getSupportedLocaleCodes());
            if ($preferred !== null && $this->isSupported($preferred)) {
                return $preferred;
            }
        }

        return $this->getDefaultLocale();
    }

    /**
     * Remembers a ?lang= choice (one year), so the next public page opens
     * in it without the parameter.
     */
    public function remember(Request $request, Response $response): Response
    {
        $queryLang = $request->query->get('lang');
        if (is_string($queryLang) && $this->isSupported($queryLang) && $request->cookies->get(self::COOKIE) !== $queryLang) {
            $response->headers->setCookie(Cookie::create(self::COOKIE, $queryLang, new \DateTimeImmutable('+1 year'), '/', null, null, true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }
}
