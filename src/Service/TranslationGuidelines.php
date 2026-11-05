<?php

namespace App\Service;

/**
 * Rules added to every menu translation prompt (dishes and owner texts,
 * categories, tags) and to its review pass. Real failures they target:
 * "Pies de cerdo" → "pork pies" (a false friend: "pies" = feet), "Ternasco
 * de cordero" → "Lamb tenderloin" (a cut of meat that isn't in the source).
 * The review used to check only for made-up or merged words, which a
 * plausible wrong translation passes.
 */
final class TranslationGuidelines
{
    /** For a dish (name + description), translating. */
    public const DISH = 'Translate like a native food writer of that language: use the established culinary term '
        . 'for each ingredient, cut and preparation, reading the dish in the context of its "menu_section" when given. '
        . 'Beware of false friends — words that look like a word of the target language but mean something else. '
        . 'Never add or change details that are not in the source (a cut of meat, an age, a size, a cooking method, an origin). '
        . 'If a regional dish or word has no established equivalent, keep its original name and add a short plain '
        . 'explanation in the target language, e.g. "Ternasco (young lamb)".';

    /** For a dish, reviewing a candidate translation. */
    public const DISH_REVIEW = 'Also check the MEANING against the original (and its "menu_section" when given): '
        . 'a false friend (a word that looks like the original but means something else), a wrong culinary term, '
        . 'or a detail that is not in the original (a cut of meat, an age, a size, a cooking method, an origin) is wrong, '
        . 'even if it reads naturally. A regional name with no established equivalent should be kept with a short plain '
        . 'explanation in the target language.';

    /** For a short label (category heading, tag), translating. */
    public const LABEL = 'Use the established term a native menu of that language would use. '
        . 'Beware of false friends — words that look like a word of the target language but mean something else. '
        . 'Never add or change details that are not in the source.';

    /** For a short label, reviewing. */
    public const LABEL_REVIEW = 'Also check the MEANING against the original: a false friend, a wrong term or a detail that '
        . 'is not in the original is wrong, even if it reads naturally.';
}
