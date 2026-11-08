<?php

namespace App\Service;

/**
 * Rules added to every menu translation prompt (dishes and owner texts,
 * categories, tags) and to its review pass — the same text whichever AI
 * answers (Groq, Gemini, OpenAI…): plain general rules, no model-specific
 * tricks, no list of words of any one language.
 *
 * Real failures they target: false friends ("Pies de cerdo" → "pork pies";
 * "braseada" → "braisée"/"geschmort", a different cooking method); a
 * different, better-known cut ("costillar" → "Schweinshaxe", "pierna" →
 * "jarret"); a set expression translated word by word ("jamón dulce" →
 * "sweet ham"); a regional name of a usual sauce taken apart ("ajoaceite"
 * → "garlic and oil" instead of aioli); an unknown variety name replaced
 * by a guess ("frac" → "chocolate"); ordinary food words left untranslated
 * because a rule said to keep unknown words as names ("Requesón",
 * "Meloso", "à l'ajoaceite"); an explanation that adds a detail
 * ("Ternasco (junge Lammkeule)": "Keule" isn't in the source — an example
 * once given here pushed models to explain that very word); a local dish
 * left with no explanation because it looked like a proper name; the same
 * wording translated two ways in one menu. The review used to check only
 * for made-up or merged words, which a plausible wrong translation passes.
 */
final class TranslationGuidelines
{
    /** For a dish (name + description), translating. */
    public const DISH = 'Translate like a native food writer of that language, using the words a menu written in that language would use for the same food:' . "\n"
        . '1. Meaning first: work out what each word means in the source language — for a regional or dialect word, in the region it comes from — before translating it. A word that looks like a word of the target language may be a false friend: translate its meaning, never its look.' . "\n"
        . '2. Cooking method: name exactly the method of the source; never swap it for a different method.' . "\n"
        . '3. Cuts and parts: name exactly the same cut or part of the animal. If you don\'t know the exact term in the target language, describe that same part plainly — never use a different, better-known cut.' . "\n"
        . '4. Fixed expressions: a set expression that names one specific product, sauce or preparation is translated by what it is — the name that thing has in the target language — never word by word.' . "\n"
        . '5. A regional or local name of a dish, sauce or product that has a usual name in the target language: use that usual name.' . "\n"
        . '6. Keep exactly as written ONLY: brand names, product and variety names, place names, names of people or producers. Ordinary food words — ingredients, cheeses, sauces, cuts, cooking methods — are always translated by their meaning, even when written in a regional or dialect form. Never replace a word with a guess.' . "\n"
        . '7. A local dish with no equivalent: keep its original name and, only if you know for certain what it is (or the description says it), add a short explanation in brackets in the target language. The explanation only says what the dish is: no cut, age, size, method or origin that the source doesn\'t state. Not sure what it is: keep the name with no explanation.' . "\n"
        . '8. Never add, drop or change anything: every ingredient, cut, cooking method, quantity, size and origin of the source is in the translation, and nothing else.' . "\n"
        . '9. The same source wording gets the same translation everywhere in this list.';

    /** For a dish, reviewing a candidate translation. */
    public const DISH_REVIEW = 'Also check the MEANING of the name and the description against the original (and its "menu_section" when given), rule by rule:' . "\n"
        . 'a false friend; a cooking method or a cut different from the source\'s; a set expression translated word by word; a regional name of something that has a usual name in the target language left as it is or taken apart; a brand, product, variety, place or person name replaced by something else (put the original name back); an ordinary food word (an ingredient, cheese, sauce, cut or cooking method) left untranslated (translate it by its meaning); an explanation in brackets that adds a detail the source doesn\'t state (remove that detail); a local dish whose meaning you know for certain left with no short explanation (add it); anything added, dropped or changed; the same source wording translated differently in this list.' . "\n"
        . 'Any of these is wrong even if the translation reads naturally.';

    /** For a short label (category heading, tag), translating. */
    public const LABEL = 'Use the term a native menu of that language would use. '
        . 'Work out the meaning in the source language first: a word that looks like a word of the target language may be a false friend. '
        . 'A set expression is translated by what it names, never word by word; a regional name that has a usual name in the target language takes that usual name. '
        . 'Keep exactly as written ONLY brands and names of products, varieties, places, people or producers; ordinary food words (ingredients, cheeses, sauces, cuts, cooking methods) are always translated by their meaning, even when written in a regional or dialect form. Never replace a word with a guess. '
        . 'Never add, drop or change details.';

    /** For a short label, reviewing. */
    public const LABEL_REVIEW = 'Also check the MEANING against the original: a false friend, a set expression translated word by word, '
        . 'a regional name that has a usual name left as it is, a name or any word replaced by a guess, '
        . 'an ordinary food word (an ingredient, cheese, sauce, cut or cooking method) left untranslated, '
        . 'or a detail added, dropped or changed is wrong, even if it reads naturally.';
}
