<?php

namespace App\Service;

/**
 * The extraction instructions handed to whichever vision provider analyzes
 * a menu page. Kept separate from GeminiVisionAdapter exactly the way
 * SmartWaiterPromptBuilder is kept separate from GeminiAdapter — the
 * adapter is pure transport, this is the only place that knows what the
 * model is actually being asked to do.
 *
 * Every rule below exists to stop one specific way of inventing menu data
 * that was never actually printed — see the class-level rationale in
 * MenuImportExtractionService for how the resulting JSON stays "extracted"
 * rather than "confirmed" until a human reviews it (a later phase).
 */
final class MenuVisionPromptBuilder
{
    /**
     * The only dietary marker codes the model is allowed to report — the
     * same codes this app's own preset ProductTag entries use (see
     * config/preset_tags.yaml). Keeping this list here, not invented ad hoc
     * by the model, is what lets a later phase match a marker straight to
     * an existing ProductTag by code.
     */
    private const KNOWN_DIETARY_CODES = ['vegetarian', 'vegan', 'spicy', 'gluten-free', 'halal'];

    /**
     * @param string $restaurantLanguage The restaurant's own configured
     *   content language (ISO 639-1, e.g. "es") — see Restaurant::$defaultLanguage.
     *   Every extracted name is persisted under this exact locale (see
     *   MenuImportAssembler), so it must also be the language the model
     *   picks whenever a menu prints something bilingually — otherwise the
     *   admin panel, which only ever looks up a category/product's name by
     *   this same locale, would find no translation to display at all.
     */
    public function build(string $restaurantLanguage): string
    {
        $codes = implode(', ', self::KNOWN_DIETARY_CODES);

        return <<<PROMPT
            You are transcribing a photograph of one page of a restaurant's printed menu. Your only job is accurate transcription — never invent, guess, or infer anything that isn't actually visible on the page.

            You are reading a real restaurant menu the way an intelligent person would. Menus vary enormously in format, language, structure, and convention. Before extracting anything, understand the menu's layout as a whole: what are the sections, what is a dish, what is a translation, a price, a note, or decoration. Apply common sense: a category always has a name; a repeated line in another language is a translation, not a new dish; text like "IVA incluido" or "pan y bebida incluidos" is a menu-wide condition, not a dish. When the printed format doesn't match any rule below exactly, resolve it the way the restaurant obviously intended, and prefer transcribing the literal printed text over inventing content or leaving a placeholder. The specific rules below exist to make this judgment concrete and consistent — they are examples of this same underlying reasoning, not an exhaustive list of formats to match against.

            STRICT RULES:
            1. Only include a category or dish that is actually printed on this page. If the page is blank, unreadable, or isn't a menu at all, return an empty "categories" array — do not invent content to fill it. Never emit a category or dish with an invented placeholder name (a generic filler like "Unnamed" or "Sin nombre" in any language) — a category or dish that's actually printed always has real text somewhere; if its layout is unusual or a heading is hard to parse, transcribe the literal printed text you can read as its name rather than falling back to a placeholder. Only leave something out entirely if genuinely nothing is legible for it.
            1b. A category's dishes are not always printed one per line — they may instead be printed as a comma-separated (or otherwise listed) enumeration directly under the category heading, with no individual dish name of its own beyond that list. In that case, each item in the enumeration is a separate dish belonging to that category: extract each one as its own product, using that item's own text as its "name" (rule 2 still applies to each one individually).

               This is a different situation from rule 2/3's ingredient enumeration, and the two must never be confused: rule 2/3 only ever applies to an enumeration attached to ONE already-identified dish's own entry (within it or trailing it). An enumeration printed directly under a category heading, where no single dish name precedes or contains it, is a list of DISHES for that category, not a list of INGREDIENTS for one dish.

               Never include, as one of these dishes, a menu-wide note or condition rather than an actual dish — text stating what's included with every order, a policy, or a similar aside is not something a customer would order as a dish. Recognize this by what the text actually says, not by its position or punctuation, and exclude it from the dish list entirely.

               For example, if a "POSTRES" category prints only "Flan, Iogurt, Cuajada con miel, Arroz con Leche, Contesa Helada, Tartas del día, Fruta fresca. Pan, agua, vino o refresco incluido." → 7 separate dishes: "Flan", "Iogurt", "Cuajada con miel", "Arroz con Leche", "Contesa Helada", "Tartas del día", "Fruta fresca". "Pan, agua, vino o refresco incluido" ("bread, water, wine, or a soft drink included") is a menu-wide condition, not a dish — exclude it entirely.
            1c. This restaurant's configured language is "{$restaurantLanguage}". A menu may print a category heading or a dish in multiple languages together — one version per language, printed as alternate lines or side by side for the exact same content. Treat every such multi-language group as ONE category or ONE dish, never more than one, and never treat another language's version as a separate dish, nor as a "description": it is a translation of the same name, nothing more. Use ONLY the "{$restaurantLanguage}" version as the "name" — every other language version is not transcribed into "name", "description", or anywhere else. If the menu doesn't print a "{$restaurantLanguage}" version of that particular item at all, fall back to whichever language the menu is primarily printed in (see rule 11) for that item instead — never leave the name empty, and never invent a translation the menu doesn't actually print. For example, if the restaurant's configured language is "es" and a dish is printed as "Carpaccio de salmó" (Catalan) directly above "Carpaccio de salmón" (Spanish) → name: "Carpaccio de salmón". If the configured language were "ca" instead → name: "Carpaccio de salmó". A category printed as "PEIXOS" (Catalan) with "Pescados" (Spanish) beneath it → name: "Pescados" when configured for Spanish, "PEIXOS" when configured for Catalan — never "PEIXOS Pescados" combined, and never two separate categories.
            1d. A menu section is sometimes itself organized into subsections — a top-level heading containing further named subheadings, each grouping its own dishes. When this happens, flatten the hierarchy to the subsection level: each subsection becomes its own category, using the subsection's own heading as that category's name (applying rules 1b/1c/2 as relevant) — never the parent section's heading, and never the parent heading prepended to it. The parent section heading itself is dropped entirely; it does not become a category of its own. (A section that has no subsections at all is unaffected by this rule — it's already just one ordinary category.) For example, a top heading "ENTRANTS" containing a subheading "CARPACCIOS" with its own dishes beneath it → one category named "CARPACCIOS" (or its bilingual form per rule 1c) holding those dishes; "ENTRANTS" is never itself output as a category, and the carpaccio dishes are never merged into a category named "ENTRANTS".
            2. The "name" field is the dish's entire printed entry, exactly as printed, MINUS only two things: a supplement annotation like "(suplemento 1.50€)" (rule 7b), and a trailing ingredient enumeration (rule 3) that sits at the very end of the dish's entry with nothing else printed after it — that trailing group is an appended ingredient list, not part of the name, so it is extracted instead of kept in "name" (never left in "name", and never duplicated into "description").

               Position in the entry — not parentheses, punctuation, or any particular layout — is what decides whether an ingredient enumeration stays in "name" or is removed from it:
               - If the entry's text continues after the ingredient enumeration, the name isn't over yet: keep the ENTIRE name exactly as printed, unstripped, and separately extract that enumeration's items (see rule 3) — a parallel extraction, not a move. For example: "Ñoquis caseros (calabaza) con salsa de queso parmesano" → name: "Ñoquis caseros (calabaza) con salsa de queso parmesano" (verbatim), ingredients: ["calabaza"].
               - If an ingredient enumeration is the LAST thing printed for that dish, with nothing else following it, it is not part of the name: remove it from "name" and extract its items as ingredients instead. For example: "Ensalada verde con remolacha (mézclum con tomate, apio, hinojo, pepino)" → name: "Ensalada verde con remolacha", ingredients: ["mézclum con tomate", "apio", "hinojo", "pepino"] (see rule 3 for why "mézclum con tomate" is one ingredient, not two).

               "description" is reserved for genuinely separate descriptive text the menu prints for that dish on its own separate line/section, distinct from the dish's own entry — never assembled from any part of the name, and never invented. If the menu prints no such separate line, "description" is null.
            3. Only include an ingredient if it appears in an explicit enumeration of ingredients for that dish — a distinguishable group of ingredient words — never a single ingredient word used descriptively within the dish's own wording. Whether that enumeration sits mid-entry or at the very end changes what happens to "name" (see rule 2), but never changes whether its items get extracted — every item in the enumeration is extracted either way. Never infer, assume, or complete an ingredient list from culinary knowledge, tradition, or a dish's descriptive wording — no matter how obvious or traditional an ingredient seems. An empty "ingredients" array is a valid, often correct, answer; when in doubt, extract fewer ingredients, never more. For example:
               - "Margherita" with description "Tomato, mozzarella, basil" → extract exactly those three ingredients, nothing else.
               - "Chicken Caesar Salad" with description "Chicken, lettuce, parmesan, croutons" → extract exactly those four ingredients.
               - "Carbonara" with no ingredient enumeration printed anywhere → extract zero ingredients. Do not add egg, guanciale, or pecorino just because a carbonara traditionally contains them.
               - "Hawaiian Pizza" with no ingredient enumeration printed anywhere → do not add ham, pineapple, cheese, or tomato.
               - "Spaghetti Bolognese" with no ingredient enumeration printed anywhere → do not add beef, tomato, onion, or garlic.
               - "Provolone tibio" (no enumeration printed at all) → extract zero ingredients — "provolone" is a single descriptive word, not an enumeration.
               - "Ñoquis caseros (calabaza) con salsa de queso parmesano" → extract "calabaza" (the enumerated group); do not also extract "queso parmesano" — it's a single descriptive word in the remaining wording, not part of any enumeration.

               Each item within an enumeration must be transcribed exactly as printed, without splitting it into smaller pieces. To decide where one item ends and the next begins, apply the ordinary list grammar of whatever language the menu is actually written in (see rule 11 for detecting it) — reason about that language's grammar itself, not a fixed word list:
               - That language's own way of separating items in a list — typically a comma, and (in many languages) a conjunction immediately before the final item — marks a boundary between distinct items.
               - A word that instead binds parts of a single item together into one compound name — part of ordinary phrasing within that one item, not a list separator — is kept inside that item's own text, not treated as a boundary.
               The Spanish/English words in the examples below illustrate this principle; they are not an exhaustive list to pattern-match against — apply the same grammatical reasoning to any language the menu is printed in.

               For example: "...virutas de queso y vinagreta" (Spanish) → two items, "virutas de queso" and "vinagreta" — here "y" is the list's final separator, while "de" is simply part of "virutas de queso"'s own name. Another example: "mézclum con tomate, apio" (Spanish) → "mézclum con tomate" is one item — here "con" binds it together rather than separating anything — followed by "apio", separated by the comma.
            4. Preserve the exact order ingredients are printed in — left to right, top to bottom. Never sort them alphabetically, never reorder by confidence or importance, never rearrange them for any other reason. The printed order is meaningful and must be kept exactly as-is. For example, if the menu prints "carrot, broccoli, zucchini, green beans, mushrooms, cauliflower, cherry tomatoes", the "ingredients" array must be in that exact sequence — never alphabetized, never grouped, never shuffled.
            5. Only include a dietary marker if an explicit symbol or word for it is printed next to the dish (a "V", a leaf icon, a chili icon, the word itself, etc.). Never infer one from the dish's name or ingredients. Use ONLY these exact codes, never invent a new one: {$codes}.
            6. Only report a price if it is printed on the page. If a price is printed but you are not confident you read it correctly, set "price" to null and "price_uncertain" to true. If no price is printed for a dish at all, set "price" to null and "price_uncertain" to false — these are different situations and must be reported differently. A menu may also mark a dish's price as "market price" using words or an abbreviation instead of a number — e.g. "S/M", "según mercado", "seasonal price" — this is a valid, deliberate way of pricing a dish, not a parsing failure or an unclear reading: treat it exactly like no price being printed, "price": null, "price_uncertain": false. Never invent a numeric guess for it, and never mark it uncertain just because the notation isn't a number.
            7. The "price" field is the dish's own base price ONLY — never add the cost of an optional extra, supplement, upgrade, add-on, or modifier to it. Menus often print optional extras below or beside a dish, each with its own "+" price (e.g. "Add cheese +$2.00", "Gluten-free base +€2.50", "Add prawns +$8.00"). These are separate optional charges, not part of the dish's price: ignore them completely — do not extract them as separate products, do not fold their cost into the dish's price, and do not mention them anywhere in the output. For example:
               - "Burger — $12.00 / Add cheese +$2.00 / Add bacon +$3.00" → price: 12.00 (never 14.00, 15.00, or 17.00).
               - "Pizza Margherita — €10.00 / Gluten-free base +€2.50" → price: 10.00.
               - "Steak — $28.00 / Add prawns +$8.00" → price: 28.00.
            7b. A different, unrelated case is a Spanish-style fixed-price menu ("menú del día"): one fixed price covers a whole starter+main+dessert meal, but a specific dish still carries its own mandatory surcharge. Recognize this regardless of exact spelling, abbreviation, capitalization, or punctuation — "(suplemento 1.50€)", "Suplemento. 1.50€", "Suplemento 2€", "supl 3€", and "supl. 4€" are all the same thing, just written differently; reason about what the text means, not one exact string. Unlike rule 7's optional extras, never ignore this — extract the amount into the separate "supplement_price" field (e.g. 1.50), and leave "price" as whatever the menu prints for that dish (often null/0, since the fixed menu price covers it). Never fold a supplement into "price", and never leave the surcharge notation sitting in the description — it belongs only in "supplement_price". If a dish has no such supplement, "supplement_price" is null.
            7c. A third, also unrelated case is a drink sold both by the glass and by the bottle, typically printed under two column headers meaning "glass" and "bottle" — e.g. "Copa / Botella" (Spanish), "Glass / Bottle" (English), "Verre / Bouteille" (French), "Calice / Bottiglia" (Italian), "Taça / Garrafa" (Portuguese), "Glas / Flasche" (German), or an equivalent pair in whatever language the menu is printed in. Recognize this by what the two headers mean, not by matching one exact pair of words — this is a general rule that applies to any menu, in any language, wherever it occurs, not just wine lists. When a drink row prints two prices under such a header pair, the smaller, per-glass price goes in the separate "glass_price" field, and the larger, per-bottle price goes in "price" — for that row, "price" always means the bottle price, never the glass price, and never their sum. This is unrelated to rule 7's optional add-ons: a glass/bottle pair is two ways of buying the same drink, not an extra charge on top of it. If a drink row on that same menu prints only one price (no glass/bottle pair for that particular row), treat it exactly like rule 6: "price" is that single printed price, and "glass_price" is null. Never invent a glass price by dividing, estimating, or guessing from the bottle price — only extract it when the menu itself actually prints a distinct per-glass price.
            8. The "description" field must contain only genuine descriptive text that is actually printed on the menu for that dish — never invent one. Critically: never build a description by copying, wrapping, or paraphrasing the ingredients list — ingredients belong ONLY in the "ingredients" array, even when they're printed in their own "Ingredients:" section rather than inline with the dish. If no real descriptive text is printed, use null for "description" — do not fall back to turning the ingredient list into a description. For example, if the menu prints:
                 "Stir-fried vegetables with quinoa and soy sauce
                 Ingredients: carrot, broccoli, zucchini, green beans, mushrooms, cauliflower, cherry tomatoes"
               → description: "Stir-fried vegetables with quinoa and soy sauce" (the real printed description), ingredients: ["carrot", "broccoli", "zucchini", "green beans", "mushrooms", "cauliflower", "cherry tomatoes"]. Never output description: "(carrot, broccoli, zucchini, green beans, mushrooms, cauliflower and cherry tomatoes)" — that is the ingredients list, not a description, and must not appear in the "description" field in any form.
            9. FIELD ISOLATION: treat "name", "description", "ingredients", and "price" as completely independent — each is filled ONLY from the specific text on the menu that IS that field. Never duplicate, move, or infer information from one field into another. A dish always needs its own printed name to be included at all, but "description" can be null, "ingredients" can be an empty array, and "price" can be null whenever the menu doesn't explicitly provide them for that field — return them empty rather than pulling content over from a different field to fill the gap. Accuracy over completeness: leaving a field empty is always better than inventing or relocating information into it.
            10. The "recommended" field must be false by default. Only set it true when the menu text explicitly marks that specific dish as a recommendation or chef's suggestion — a literal word or phrase like "Recomendado", "Sugerencia del Chef", "Especialidad de la casa", "Chef's Recommendation", or an icon that is itself labeled with one of those phrases. Never set it true from position on the page, price, bold/larger text, a star symbol with no accompanying words, or any other implied "popularity" or "best seller" signal — this platform has no sales data, so nothing here may imply one.
            11. Report the language the menu is actually printed in as "detected_language" (an ISO 639-1 code, e.g. "en", "es", "it"). Do not translate anything — every name, description, and ingredient must be transcribed exactly as printed, in that original language.
            12. Whenever you are not fully confident about a name or description, set its matching "*_uncertain" field to true rather than silently guessing and marking it certain.

            Respond with ONLY a single valid JSON object matching this exact shape — no markdown, no explanation, nothing else:
            {
              "detected_language": "en",
              "categories": [
                {
                  "name": "string",
                  "products": [
                    {
                      "name": "string",
                      "name_uncertain": false,
                      "description": "string or null",
                      "description_uncertain": false,
                      "price": 0.00,
                      "price_uncertain": false,
                      "supplement_price": null,
                      "glass_price": null,
                      "recommended": false,
                      "ingredients": [{"name": "string", "uncertain": false}],
                      "dietary_markers": [{"code": "string", "uncertain": false}]
                    }
                  ]
                }
              ]
            }
            PROMPT;
    }
}
