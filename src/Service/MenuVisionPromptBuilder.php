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

    public function build(): string
    {
        $codes = implode(', ', self::KNOWN_DIETARY_CODES);

        return <<<PROMPT
            You are transcribing a photograph of one page of a restaurant's printed menu. Your only job is accurate transcription — never invent, guess, or infer anything that isn't actually visible on the page.

            STRICT RULES:
            1. Only include a category or dish that is actually printed on this page. If the page is blank, unreadable, or isn't a menu at all, return an empty "categories" array — do not invent content to fill it.
            2. The "name" field must contain ONLY the dish name exactly as printed — never append ingredients, side dishes, garnishes, sauces, allergens, preparation notes, or a description to it. If ingredients are printed next to or below the dish name, they belong only in the "ingredients" array, never in "name". Do not include parenthetical text after the dish name unless the parentheses are clearly part of the dish's own official printed name — not a list of what's inside it. When genuinely uncertain how much of the printed text is the name versus something else, prefer the shorter reading. For example, if the menu prints:
                 "Provolone tibio"
                 "mézclum, tomate, apio, pepino e hinojo"
               → name: "Provolone tibio", ingredients: ["mézclum", "tomate", "apio", "pepino", "hinojo"]. Never output name: "Provolone tibio con ensalada (mézclum, tomate, apio, pepino e hinojo)".
            3. Only include an ingredient if it is explicitly listed on the menu for that dish — word for word, wherever it's printed: in the dish's name, its description, or a separate ingredients list/section next to it. Never infer, assume, or complete an ingredient list from culinary knowledge, tradition, or the dish's name — no matter how obvious or traditional an ingredient seems. An empty "ingredients" array is a valid, often correct, answer; when in doubt, extract fewer ingredients, never more. For example:
               - "Margherita" with description "Tomato, mozzarella, basil" → extract exactly those three ingredients, nothing else.
               - "Chicken Caesar Salad" with description "Chicken, lettuce, parmesan, croutons" → extract exactly those four ingredients.
               - "Carbonara" with no ingredient list printed anywhere → extract zero ingredients. Do not add egg, guanciale, or pecorino just because a carbonara traditionally contains them.
               - "Hawaiian Pizza" with no ingredient list printed anywhere → do not add ham, pineapple, cheese, or tomato.
               - "Spaghetti Bolognese" with no ingredient list printed anywhere → do not add beef, tomato, onion, or garlic.
            4. Preserve the exact order ingredients are printed in — left to right, top to bottom. Never sort them alphabetically, never reorder by confidence or importance, never rearrange them for any other reason. The printed order is meaningful and must be kept exactly as-is. For example, if the menu prints "carrot, broccoli, zucchini, green beans, mushrooms, cauliflower, cherry tomatoes", the "ingredients" array must be in that exact sequence — never alphabetized, never grouped, never shuffled.
            5. Only include a dietary marker if an explicit symbol or word for it is printed next to the dish (a "V", a leaf icon, a chili icon, the word itself, etc.). Never infer one from the dish's name or ingredients. Use ONLY these exact codes, never invent a new one: {$codes}.
            6. Only report a price if it is printed on the page. If a price is printed but you are not confident you read it correctly, set "price" to null and "price_uncertain" to true. If no price is printed for a dish at all, set "price" to null and "price_uncertain" to false — these are different situations and must be reported differently.
            7. The "price" field is the dish's own base price ONLY — never add the cost of an optional extra, supplement, upgrade, add-on, or modifier to it. Menus often print optional extras below or beside a dish, each with its own "+" price (e.g. "Add cheese +$2.00", "Gluten-free base +€2.50", "Add prawns +$8.00"). These are separate optional charges, not part of the dish's price: ignore them completely — do not extract them as separate products, do not fold their cost into the dish's price, and do not mention them anywhere in the output. For example:
               - "Burger — $12.00 / Add cheese +$2.00 / Add bacon +$3.00" → price: 12.00 (never 14.00, 15.00, or 17.00).
               - "Pizza Margherita — €10.00 / Gluten-free base +€2.50" → price: 10.00.
               - "Steak — $28.00 / Add prawns +$8.00" → price: 28.00.
            8. The "description" field must contain only genuine descriptive text that is actually printed on the menu for that dish — never invent one. Critically: never build a description by copying, wrapping, or paraphrasing the ingredients list — ingredients belong ONLY in the "ingredients" array, even when they're printed in their own "Ingredients:" section rather than inline with the dish. If no real descriptive text is printed, use null for "description" — do not fall back to turning the ingredient list into a description. For example, if the menu prints:
                 "Stir-fried vegetables with quinoa and soy sauce
                 Ingredients: carrot, broccoli, zucchini, green beans, mushrooms, cauliflower, cherry tomatoes"
               → description: "Stir-fried vegetables with quinoa and soy sauce" (the real printed description), ingredients: ["carrot", "broccoli", "zucchini", "green beans", "mushrooms", "cauliflower", "cherry tomatoes"]. Never output description: "(carrot, broccoli, zucchini, green beans, mushrooms, cauliflower and cherry tomatoes)" — that is the ingredients list, not a description, and must not appear in the "description" field in any form.
            9. FIELD ISOLATION: treat "name", "description", "ingredients", and "price" as completely independent — each is filled ONLY from the specific text on the menu that IS that field. Never duplicate, move, or infer information from one field into another. A dish always needs its own printed name to be included at all, but "description" can be null, "ingredients" can be an empty array, and "price" can be null whenever the menu doesn't explicitly provide them for that field — return them empty rather than pulling content over from a different field to fill the gap. Accuracy over completeness: leaving a field empty is always better than inventing or relocating information into it.
            10. Never assign any kind of recommendation, popularity, "chef's pick", or "best seller" status to anything — this schema has no field for it, and nothing you output should imply it either.
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
