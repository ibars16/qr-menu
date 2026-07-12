<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves each individual customer's language & currency preference for the
 * public menu. This is entirely per-browser, client-side state — it is never
 * read from or written to the Restaurant entity (Restaurant::$defaultLanguage
 * / $currency) or any user account, and it must never be confused with the
 * Admin Panel's own language (AdminLocaleResolver), which is a restaurant-wide
 * staff setting.
 */
class MenuPreferencesResolver
{
    public const COOKIE_NAME = 'menu_prefs';

    /** @var array<string, array{name: string, flag: string}> */
    private array $languages;

    /** @var array<string, array{symbol: string, name: string}> */
    private array $currencies;

    /** @var array<string, string> ISO 3166-1 region => ISO 4217 currency */
    private array $localeCurrencyMap;

    public function __construct(string $projectDir)
    {
        $this->languages         = require $projectDir . '/config/languages.php';
        $this->currencies        = require $projectDir . '/config/currencies.php';
        $this->localeCurrencyMap = require $projectDir . '/config/locale_currency_map.php';
    }

    /** @return array<string, array{name: string, flag: string}> */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    /** @return array<string, array{symbol: string, name: string}> */
    public function getCurrencies(): array
    {
        return $this->currencies;
    }

    public function isLanguageSupported(?string $code): bool
    {
        return $code !== null && isset($this->languages[$code]);
    }

    public function isCurrencySupported(?string $code): bool
    {
        return $code !== null && isset($this->currencies[$code]);
    }

    /**
     * Best supported language for this device/browser, based on its
     * Accept-Language header, falling back when nothing supported matches.
     */
    public function detectLanguage(Request $request, string $fallback): string
    {
        return $request->getPreferredLanguage(array_keys($this->languages)) ?? $fallback;
    }

    /**
     * Guesses the customer's currency from the region half of their
     * Accept-Language tags (e.g. "es-AR" → ARS), in preference order. Falls
     * back when no region matches a supported currency.
     */
    public function guessCurrency(Request $request, string $fallback): string
    {
        foreach ($request->getLanguages() as $tag) {
            $region = strtoupper(substr((string) strrchr($tag, '_'), 1));
            if ($region === '') {
                continue;
            }

            $currency = $this->localeCurrencyMap[$region] ?? null;
            if ($currency !== null && $this->isCurrencySupported($currency)) {
                return $currency;
            }
        }

        return $fallback;
    }

    /**
     * Reads the customer's previously-saved preference cookie.
     *
     * @return array{lang: ?string, currency: ?string}|null null means this is a first-time visitor (no cookie yet)
     */
    public function readCookie(Request $request): ?array
    {
        $raw = $request->cookies->get(self::COOKIE_NAME);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'lang'     => $this->isLanguageSupported($data['lang'] ?? null) ? $data['lang'] : null,
            'currency' => $this->isCurrencySupported($data['currency'] ?? null) ? $data['currency'] : null,
        ];
    }
}
