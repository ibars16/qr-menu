<?php

namespace App\Service;

/**
 * Formats a price's numeric part (decimal/thousands separators) for the
 * public menu, keyed to the diner's chosen menu-content language — never to
 * currency or to Symfony's own request locale, which stay on entirely
 * separate axes (see MenuPreferencesResolver's class docblock). This never
 * touches the currency symbol/code or its position, only the digits.
 *
 * Hand-rolled table instead of ext-intl's NumberFormatter: the app's runtime
 * container doesn't ship ext-intl, and Symfony's polyfill stand-in for
 * NumberFormatter only implements the "en" locale (throws for anything
 * else). Values below are CLDR decimal/grouping separators, one entry per
 * config/languages.php code — verified against ext-intl on a host that does
 * have it (never shipped here, used only to build this table).
 *
 * `ar`'s pair is '.'/',' (the Western-digit convention), not the '٫'/'٬'
 * Arabic-Indic-digit pair — number_format() always emits Latin digits, and
 * CLDR's own Latin-digit variant for ar (`ar-u-nu-latn`) resolves to '.'/',',
 * matching en/zh/ja/ko/th/hi rather than the es/de-style pair.
 *
 * `hi`'s pair only matches true Indian lakh/crore grouping (1,25,000) below
 * 100,000 — this table does plain 3-digit grouping throughout, a known gap
 * for higher amounts.
 */
class MenuNumberFormatter
{
    private const string THIN_NBSP = "\u{202F}";
    private const string NBSP      = "\u{00A0}";

    /** @var array<string, array{decimal: string, thousand: string}> */
    private const array SEPARATORS = [
        'en' => ['decimal' => '.', 'thousand' => ','],
        'es' => ['decimal' => ',', 'thousand' => '.'],
        'fr' => ['decimal' => ',', 'thousand' => self::THIN_NBSP],
        'de' => ['decimal' => ',', 'thousand' => '.'],
        'it' => ['decimal' => ',', 'thousand' => '.'],
        'pt' => ['decimal' => ',', 'thousand' => '.'],
        'nl' => ['decimal' => ',', 'thousand' => '.'],
        'ru' => ['decimal' => ',', 'thousand' => self::NBSP],
        'zh' => ['decimal' => '.', 'thousand' => ','],
        'ja' => ['decimal' => '.', 'thousand' => ','],
        'ko' => ['decimal' => '.', 'thousand' => ','],
        'th' => ['decimal' => '.', 'thousand' => ','],
        'ar' => ['decimal' => '.', 'thousand' => ','],
        'hi' => ['decimal' => '.', 'thousand' => ','],
        'tr' => ['decimal' => ',', 'thousand' => '.'],
        'vi' => ['decimal' => ',', 'thousand' => '.'],
        'id' => ['decimal' => ',', 'thousand' => '.'],
        'pl' => ['decimal' => ',', 'thousand' => self::NBSP],
        'sv' => ['decimal' => ',', 'thousand' => self::NBSP],
        'no' => ['decimal' => ',', 'thousand' => self::NBSP],
    ];

    /** Never throws: an unmapped locale falls back to plain '.' with no grouping. */
    public function format(float $amount, string $locale): string
    {
        $sep = self::SEPARATORS[$locale] ?? ['decimal' => '.', 'thousand' => ''];

        return number_format($amount, 2, $sep['decimal'], $sep['thousand']);
    }
}
