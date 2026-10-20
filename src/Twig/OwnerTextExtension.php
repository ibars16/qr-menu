<?php

namespace App\Twig;

use App\Service\MenuPreferencesResolver;
use App\Service\OwnerTextTranslationService;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * owner_text(text, locale): an owner's free text (tagline, price label,
 * set-menu description/section) as the public menu shows it —
 * - its translation into $locale, when one is stored (see
 *   OwnerTextTranslationService::primeForMenu(), called by MenuController);
 * - otherwise the original, marked with the language it's written in
 *   (lang, so screen readers read it as such) and dir="auto" on RTL menus
 *   so a Spanish line keeps its own direction inside an Arabic page;
 * - plain, unwrapped, when $locale is the text's own language.
 * Never calls the AI.
 */
class OwnerTextExtension extends AbstractExtension
{
    public function __construct(
        private readonly OwnerTextTranslationService $ownerTexts,
        private readonly MenuPreferencesResolver     $menuPreferencesResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('owner_text', [$this, 'ownerText']),
        ];
    }

    public function ownerText(?string $text, string $locale): Markup
    {
        $text = trim((string) $text);
        if ($text === '') {
            return new Markup('', 'UTF-8');
        }

        $translated = $this->ownerTexts->translationOf($text);
        if ($translated !== null) {
            return new Markup(htmlspecialchars($translated, ENT_QUOTES, 'UTF-8'), 'UTF-8');
        }

        $sourceLocale = $this->ownerTexts->sourceLocale();
        $attrs = '';
        if ($sourceLocale !== null && $sourceLocale !== $locale) {
            $attrs .= ' lang="' . htmlspecialchars($sourceLocale, ENT_QUOTES, 'UTF-8') . '"';
        }
        if ($sourceLocale !== $locale && $this->menuPreferencesResolver->getTextDirection($locale) === 'rtl') {
            $attrs .= ' dir="auto"';
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return new Markup($attrs === '' ? $escaped : '<span' . $attrs . '>' . $escaped . '</span>', 'UTF-8');
    }
}
