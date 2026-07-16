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
            2. Only include an ingredient if it is explicitly listed on the menu for that dish — word for word, wherever it's printed: in the dish's name, its description, or a separate ingredients list/section next to it. Never infer, assume, or complete an ingredient list from culinary knowledge, tradition, or the dish's name — no matter how obvious or traditional an ingredient seems. An empty "ingredients" array is a valid, often correct, answer; when in doubt, extract fewer ingredients, never more. For example:
               - "Margherita" with description "Tomato, mozzarella, basil" → extract exactly those three ingredients, nothing else.
               - "Chicken Caesar Salad" with description "Chicken, lettuce, parmesan, croutons" → extract exactly those four ingredients.
               - "Carbonara" with no ingredient list printed anywhere → extract zero ingredients. Do not add egg, guanciale, or pecorino just because a carbonara traditionally contains them.
               - "Hawaiian Pizza" with no ingredient list printed anywhere → do not add ham, pineapple, cheese, or tomato.
               - "Spaghetti Bolognese" with no ingredient list printed anywhere → do not add beef, tomato, onion, or garlic.
            3. Only include a dietary marker if an explicit symbol or word for it is printed next to the dish (a "V", a leaf icon, a chili icon, the word itself, etc.). Never infer one from the dish's name or ingredients. Use ONLY these exact codes, never invent a new one: {$codes}.
            4. Only report a price if it is printed on the page. If a price is printed but you are not confident you read it correctly, set "price" to null and "price_uncertain" to true. If no price is printed for a dish at all, set "price" to null and "price_uncertain" to false — these are different situations and must be reported differently.
            5. The "price" field is the dish's own base price ONLY — never add the cost of an optional extra, supplement, upgrade, add-on, or modifier to it. Menus often print optional extras below or beside a dish, each with its own "+" price (e.g. "Add cheese +$2.00", "Gluten-free base +€2.50", "Add prawns +$8.00"). These are separate optional charges, not part of the dish's price: ignore them completely — do not extract them as separate products, do not fold their cost into the dish's price, and do not mention them anywhere in the output. For example:
               - "Burger — $12.00 / Add cheese +$2.00 / Add bacon +$3.00" → price: 12.00 (never 14.00, 15.00, or 17.00).
               - "Pizza Margherita — €10.00 / Gluten-free base +€2.50" → price: 10.00.
               - "Steak — $28.00 / Add prawns +$8.00" → price: 28.00.
            6. Never invent a description. If none is printed, use null for "description".
            7. Never assign any kind of recommendation, popularity, "chef's pick", or "best seller" status to anything — this schema has no field for it, and nothing you output should imply it either.
            8. Report the language the menu is actually printed in as "detected_language" (an ISO 639-1 code, e.g. "en", "es", "it"). Do not translate anything — every name, description, and ingredient must be transcribed exactly as printed, in that original language.
            9. Whenever you are not fully confident about a name or description, set its matching "*_uncertain" field to true rather than silently guessing and marking it certain.

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
