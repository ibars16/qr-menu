<?php

namespace App\Service;

/**
 * Which language a restaurant's own menu texts are written in — asked once
 * when the owner changes the menu's main language (MenuLanguageChanger), to
 * tell "the texts already are in the new language, only the label was
 * wrong" from "the texts must be translated".
 */
interface MenuLanguageDetectorInterface
{
    public const MIXED = 'mixed';

    /**
     * @param  list<string> $texts  a sample of the owner's dish/category names
     * @return string|null  the ISO 639-1 code nearly all of them are written
     *                      in, self::MIXED when they're in several languages,
     *                      null when the AI couldn't tell (or failed)
     */
    public function detect(array $texts): ?string;

    /**
     * The language of each text on its own — to tell whether a text already
     * stored for the new language really is in it (old data may hold
     * another language under that label).
     *
     * @param  array<string, string> $texts  key => text
     * @return array<string, string|null>|null  key => ISO 639-1 code, null
     *         for a text the AI can't tell (a proper name, a word the same
     *         in both languages: "Pizza margarita"); null when the call failed
     */
    public function detectEach(array $texts): ?array;
}
