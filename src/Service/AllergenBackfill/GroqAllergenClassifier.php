<?php

namespace App\Service\AllergenBackfill;

use App\Service\AI\AIFailureReason;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIProviderInterface;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;

/**
 * The two AI passes behind IngredientAllergenBackfill, both on groq-llama
 * (gpt-oss-120b) and nothing else — no AIModelRouter fallback, so a run's
 * results always come from the one model the review list was calibrated
 * on. classify() proposes allergens for a batch of ingredient names;
 * review() is a separate call with its own prompt that sees those
 * proposals and confirms or corrects each one. IngredientAllergenBackfill
 * compares the two — the model is never asked to grade its own confidence.
 *
 * Every name is sent with its real language code (a private ingredient
 * named in Catalan is classified from the Catalan name, not an English
 * rendering of it).
 */
class GroqAllergenClassifier
{
    private const PROVIDER_ID = 'groq-llama';
    // Groq's free tier allows 8,000 tokens a minute and reserves max_tokens up front:
    // 6,000 made a single call nearly fill the minute. Real answers average ~400.
    private const MAX_TOKENS = 3000;
    private const RETRIES = 3;

    private const ALLERGEN_GUIDE = 'The 14 allergen codes (EU Regulation 1169/2011, Annex II) and what each covers: '
        . 'gluten = cereals containing gluten: wheat (incl. spelt, kamut), rye, barley, oats, and anything made from them (flour, bread, pasta, couscous, malt, beer); '
        . 'crustaceans = shrimp, prawns, crab, lobster, crayfish, langoustine; '
        . 'eggs = eggs of any bird and anything made with them (mayonnaise, meringue, egg pasta); '
        . 'fish = any fin fish and products made from it (fish sauce, anchovy paste, surimi); '
        . 'peanuts = peanuts and peanut products; '
        . 'soybeans = soy and soy products (tofu, soy sauce, miso, soy lecithin); '
        . 'milk = milk of any mammal and dairy made from it (butter, cream, cheese, yogurt, whey, lactose); '
        . 'nuts = tree nuts ONLY: almond, hazelnut, walnut, cashew, pecan, Brazil nut, pistachio, macadamia (NOT coconut, nutmeg, pine nut, chestnut or peanut); '
        . 'celery = celery and celeriac; mustard = mustard in any form; sesame = sesame seeds, oil, tahini; '
        . 'sulphites = sulphur dioxide/sulphites, as found in wine, cava, many vinegars and dried fruit; lupin = lupin seeds and flour; '
        . 'molluscs = mussels, clams, oysters, scallops, squid, octopus, cuttlefish, snails (NOT oyster mushrooms).';

    private const RULES = 'Judge each ingredient as a food-safety-conscious cook would understand it in its usual culinary form. '
        . 'Use "contains" when the ingredient is, or is normally made from, that allergen. '
        . 'Use "may_contain" only for a real, common cross-contamination or variable-recipe risk — never as a hedge for something it plainly contains. '
        . 'Names that merely resemble an allergen do not carry it: coconut/oat/soy/almond milk are not milk, peanut butter is not milk, oyster mushroom is not a mollusc, nutmeg and coconut are not nuts. '
        . 'Variants of the same base ingredient (e.g. "Egg", "Egg white", "Free range eggs", "Chicken egg") must get the same allergens for that base. '
        . 'A plain ingredient with none of the 14 (water, salt, sugar, a fresh vegetable, plain meat) gets an empty "allergens" object. '
        . 'Set "unsure": true when the name is ambiguous, is not really an ingredient, or the answer depends on a recipe you cannot know — never guess silently.';

    private const FORMAT = 'Return ONLY a valid JSON object — no markdown fences, no explanations — mapping each item\'s "id" to '
        . '{"allergens": {"<code>": "contains"|"may_contain", ...}, "unsure": true|false}. Use only the 14 codes above. Include every id.';

    private int $promptTokens = 0;
    private int $completionTokens = 0;
    private int $calls = 0;

    private ?AIProviderInterface $provider = null;

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly AIProviderFactory $providerFactory,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $seconds) => sleep($seconds);
    }

    /**
     * @param  array<int, array{name: string, locale: string}> $items
     * @return array<int, array{allergens: array<string, string>, unsure: bool}>
     */
    public function classify(array $items): array
    {
        $payload = [];
        foreach ($items as $id => $item) {
            $payload[] = ['id' => $id, 'name' => $item['name'], 'lang' => $item['locale']];
        }

        $system = 'You classify restaurant ingredients against the 14 EU food allergens. '
            . 'Each ingredient\'s "name" is written in the language whose ISO 639-1 code is its "lang" — read it in that language. '
            . self::ALLERGEN_GUIDE . ' ' . self::RULES . ' ' . self::FORMAT;

        return $this->call($system, 'Ingredients: ' . json_encode($payload, JSON_UNESCAPED_UNICODE), array_keys($items));
    }

    /**
     * @param  array<int, array{name: string, locale: string}> $items
     * @param  array<int, array{allergens: array<string, string>, unsure: bool}> $proposals classify()'s answer for the same items
     * @return array<int, array{allergens: array<string, string>, unsure: bool}>
     */
    public function review(array $items, array $proposals): array
    {
        $payload = [];
        foreach ($items as $id => $item) {
            $payload[] = [
                'id' => $id,
                'name' => $item['name'],
                'lang' => $item['locale'],
                'proposed_allergens' => (object) ($proposals[$id]['allergens'] ?? []),
            ];
        }

        $system = 'You are a food-safety reviewer checking allergen labels another assistant proposed for restaurant ingredients. '
            . 'Each ingredient\'s "name" is written in the language whose ISO 639-1 code is its "lang". '
            . 'For each one, check "proposed_allergens" critically against the name: is any allergen missing, wrong, or given the wrong presence? '
            . 'If the proposal is correct, return it unchanged; if not, return the corrected set. '
            . self::ALLERGEN_GUIDE . ' ' . self::RULES . ' ' . self::FORMAT;

        return $this->call($system, 'Ingredients to review: ' . json_encode($payload, JSON_UNESCAPED_UNICODE), array_keys($items));
    }

    /** @return array{calls: int, promptTokens: int, completionTokens: int} */
    public function getUsage(): array
    {
        return ['calls' => $this->calls, 'promptTokens' => $this->promptTokens, 'completionTokens' => $this->completionTokens];
    }

    /**
     * @param  list<int> $ids
     * @return array<int, array{allergens: array<string, string>, unsure: bool}> only well-formed entries for known ids
     * @throws AIProviderException when groq-llama isn't configured or keeps failing
     */
    private function call(string $system, string $user, array $ids): array
    {
        $request = new AIRequest(
            systemPrompt: $system,
            messages: [new ChatMessage('user', $user)],
            maxTokens: self::MAX_TOKENS,
            temperature: 0.0,
        );

        $provider = $this->provider ??= $this->providerFactory->getProviderById(self::PROVIDER_ID)
            ?? throw new AIProviderException(self::PROVIDER_ID, AIFailureReason::HTTP_ERROR, self::PROVIDER_ID . ' is not configured (GROQ_API_KEY)');

        for ($attempt = 0; ; $attempt++) {
            try {
                $this->calls++;
                $response = $provider->complete($request);
                break;
            } catch (AIProviderException $e) {
                if (!in_array($e->reason, [AIFailureReason::RATE_LIMITED, AIFailureReason::TIMEOUT], true) || $attempt >= self::RETRIES) {
                    throw $e;
                }
                ($this->sleeper)(20 * ($attempt + 1));
            }
        }

        $this->promptTokens += $response->promptTokens ?? 0;
        $this->completionTokens += $response->completionTokens ?? 0;

        return $this->parse($response->content, $ids);
    }

    /**
     * @param  list<int> $ids
     * @return array<int, array{allergens: array<string, string>, unsure: bool}>
     */
    private function parse(string $text, array $ids): array
    {
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', trim($text));

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($ids as $id) {
            $entry = $decoded[$id] ?? $decoded[(string) $id] ?? null;
            if (!is_array($entry) || !array_key_exists('allergens', $entry)) {
                continue;
            }

            $allergens = [];
            foreach ((array) $entry['allergens'] as $code => $presence) {
                // Unknown codes and presences are kept as-is: the caller
                // treats them as a disagreement worth a human look rather
                // than silently dropping what the model said.
                $allergens[(string) $code] = is_string($presence) ? strtolower($presence) : 'invalid';
            }
            ksort($allergens);

            $result[$id] = ['allergens' => $allergens, 'unsure' => (bool) ($entry['unsure'] ?? false)];
        }

        return $result;
    }
}
