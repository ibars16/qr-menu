<?php

namespace App\Service;

/**
 * The same text gets the same translation across a menu: two dishes (or
 * categories, or tags) of one restaurant whose source — the language it's
 * written in and its exact text (a dish: name and description) — is the
 * same share their translation into a given language, instead of the AI
 * translating it twice, possibly two different ways (seen: one Spanish
 * pizza name became "saucisse de Valls" on one dish and "butifarra de
 * Valls" on the other). Used by AiProductTranslator, AiCategoryTranslator
 * and AiTagTranslator; their section/category is not part of it, on
 * purpose. Exact text: any difference (a letter, a capital, a description)
 * is another source.
 */
final class TranslationReuse
{
    /** What two sources must share to share a translation. */
    public static function key(string $sourceLocale, string $name, ?string $description = null): string
    {
        return $sourceLocale . "\0" . trim($name) . "\0" . trim((string) $description);
    }
}
