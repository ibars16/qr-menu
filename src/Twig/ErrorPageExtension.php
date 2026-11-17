<?php

namespace App\Twig;

use App\Service\MenuPreferencesResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * error_locale(): language of the custom error pages
 * (templates/bundles/TwigBundle/Exception/error.html.twig), one of the
 * public menu's 20 (config/languages.php) — those pages are mostly reached
 * by diners (an old QR, a removed restaurant). Same order as the menu
 * itself: ?lang= → the menu_prefs cookie → the browser's Accept-Language
 * → Spanish.
 */
class ErrorPageExtension extends AbstractExtension
{
    private const FALLBACK = 'es';

    public function __construct(
        private readonly MenuPreferencesResolver $menuPreferencesResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('error_locale', $this->errorLocale(...)),
        ];
    }

    public function errorLocale(): string
    {
        $request = $this->requestStack->getMainRequest() ?? $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return self::FALLBACK;
        }

        $queryLang = $request->query->get('lang');
        if (is_string($queryLang) && $this->menuPreferencesResolver->isLanguageSupported($queryLang)) {
            return $queryLang;
        }

        $cookieLang = $this->menuPreferencesResolver->readCookie($request)['lang'] ?? null;
        if ($cookieLang !== null) {
            return $cookieLang;
        }

        // Not getPreferredLanguage(): it falls back to the first candidate
        // ('en') instead of Spanish when nothing in the header matches.
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok(str_replace('-', '_', $language), '_'));
            // Browsers send Norwegian as Bokmål/Nynorsk; the menu calls it 'no'.
            if ($primary === 'nb' || $primary === 'nn') {
                $primary = 'no';
            }
            if ($this->menuPreferencesResolver->isLanguageSupported($primary)) {
                return $primary;
            }
        }

        return self::FALLBACK;
    }
}
