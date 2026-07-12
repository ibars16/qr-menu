<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves and validates the language used for the Admin Panel UI.
 *
 * This is entirely separate from the public menu's language(s)
 * (Restaurant::$defaultLanguage / config/languages.php), which must never
 * be affected by the admin's own UI language preference.
 */
class AdminLocaleResolver
{
    private const DEFAULT_LOCALE = 'es';

    /** @var array<string, array{name: string, flag: string}> */
    private array $locales;

    public function __construct(string $projectDir)
    {
        $this->locales = require $projectDir . '/config/admin_locales.php';
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

    public function resolve(?string $locale): string
    {
        return $locale !== null && $this->isSupported($locale) ? $locale : $this->getDefaultLocale();
    }

    /**
     * Detects the best matching admin locale from the browser/device's
     * Accept-Language header, falling back to the app default when the
     * device language isn't supported.
     */
    public function resolveFromRequest(Request $request): string
    {
        $preferred = $request->getPreferredLanguage($this->getSupportedLocaleCodes());

        return $preferred ?? $this->getDefaultLocale();
    }
}
