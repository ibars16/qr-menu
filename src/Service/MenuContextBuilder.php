<?php

namespace App\Service;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTag;
use App\Entity\Restaurant;
use App\Repository\AllergenRepository;

/**
 * Builds the exact, restaurant-scoped, locale-resolved menu data Smart
 * Waiter is allowed to talk about — nothing more. Mirrors
 * ProductAllergenResolver's approach (batched, computed at read time) and
 * feeds it straight in rather than recomputing allergen logic here.
 *
 * Security note: this only ever walks $restaurant->getCategories() — the
 * Restaurant instance the caller passes in, resolved server-side from the
 * URL slug. There is no code path here that could pull in another
 * restaurant's data; isolation is structural, not a prompt instruction.
 *
 * "Recommended" (and any future highlight code) is reported as a
 * `highlighted` array on each product, driven only by ProductTag::$code +
 * isSystem — never by a tag's display name — so an owner renaming "Chef's
 * Recommendation" to anything else never breaks this. See ProductTag's
 * class docblock for the identity guarantee this relies on.
 *
 * Every other tag and every allergen goes through SmartWaiterDishSafety:
 * "labels" ("none" when there's none), grouped "allergens" ("unknown
 * (not specified by the restaurant)"), "contains-…" tags folded into the allergens and diet tags the
 * allergens contradict left out — see its class docblock.
 */
final class MenuContextBuilder
{
    /**
     * The only codes Smart Waiter currently knows how to talk about as
     * "highlights". Adding a future one (e.g. "seasonal") is exactly this:
     * one more code here, one more line in SystemPromptBuilder explaining
     * what it means — no other change required anywhere in this pipeline.
     */
    public const KNOWN_HIGHLIGHT_CODES = ['recommended'];

    /**
     * "spicy_level" as words, not a number the AI could quote ("nivel 3"):
     * the public menu's own 1–5 scale (dish.spice_1…5). 0 or unset → null.
     */
    public const SPICE_WORDS = [1 => 'mild', 2 => 'medium', 3 => 'spicy', 4 => 'very spicy', 5 => 'extreme'];

    public function __construct(
        private readonly ProductAllergenResolver $allergenResolver,
        private readonly CurrencyConverter $currencyConverter,
        private readonly OwnerTextTranslationService $ownerTexts,
        private readonly SmartWaiterDishSafety $dishSafety,
        private readonly AllergenRepository $allergenRepository,
    ) {}

    /** @var array<string, \App\Entity\Allergen>|null per build(), loaded only if a "contains-…" tag needs it */
    private ?array $allergensByCode = null;

    /**
     * $currency is the customer's own chosen/resolved currency (same
     * resolution as MenuController's public menu — see
     * SmartWaiterController::chat()), never $restaurant->getCurrency()
     * directly: every price below is converted from the restaurant's base
     * currency into it, so Smart Waiter never quotes a price in a different
     * currency than the one the customer is currently looking at on the
     * menu page.
     */
    public function build(Restaurant $restaurant, string $locale, string $currency): array
    {
        $this->allergensByCode = null;
        $categories = $restaurant->getCategories()
            ->filter(fn (Category $c) => $c->isActive())
            ->toArray();
        // Same position (a new one gets count() after a delete): oldest first.
        usort($categories, fn (Category $a, Category $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        $allProducts = [];
        foreach ($categories as $category) {
            foreach ($category->getProducts() as $product) {
                if ($product->isShownOnMenu()) {
                    $allProducts[] = $product;
                }
            }
        }
        $allergensByProduct = $this->allergenResolver->resolveForProducts($allProducts);
        // Same ingredient names, in the same order, as the menu's dish detail.
        // Every product of the active categories, not just $allProducts: a
        // set-menu section filters its dishes by its own rules.
        $ingredientDictionary = $this->ownerTexts->ingredientDictionary(
            $restaurant,
            $locale,
            array_merge([], ...array_map(fn (Category $c) => $c->getProducts()->toArray(), array_values($categories))),
        );

        $result = [
            'restaurant_name' => $restaurant->getName(),
            'currency' => $currency,
            'categories' => [],
        ];

        foreach ($categories as $category) {
            if ($category->isFixedPriceMenu()) {
                $sections = [];
                foreach ($category->getActiveSectionsWithProducts() as $entry) {
                    if (empty($entry['products'])) {
                        continue;
                    }
                    $sections[] = [
                        'label' => $entry['section']->getLabel(),
                        'dishes' => array_map(
                            fn (Product $p) => $this->buildProduct($p, $locale, $restaurant, $currency, $allergensByProduct[$p->getId()] ?? [], $ingredientDictionary, true),
                            $entry['products']
                        ),
                    ];
                }

                if (empty($sections)) {
                    continue;
                }

                $result['categories'][] = [
                    'name' => $this->categoryName($category, $locale, $restaurant),
                    'type' => $category->getType()?->value,
                    'menu_price' => $this->convertedDecimal($category->getMenuPrice() ?? 0, $restaurant, $currency),
                    'menu_description' => $category->getMenuDescription(),
                    'sections' => $sections,
                ];
                continue;
            }

            $products = $category->getActiveProductsSorted();

            if (empty($products)) {
                continue;
            }

            $result['categories'][] = [
                'name' => $this->categoryName($category, $locale, $restaurant),
                'type' => $category->getType()?->value,
                'products' => array_map(
                    fn (Product $p) => $this->buildProduct($p, $locale, $restaurant, $currency, $allergensByProduct[$p->getId()] ?? [], $ingredientDictionary),
                    $products
                ),
            ];
        }

        return $result;
    }

    /**
     * Flat, deduped list of this restaurant's own allergen + dietary tag
     * names in the given locale — free byproduct of the context that's
     * already built, used by HeuristicChatComplexityClassifier as a
     * restaurant-specific, language-agnostic signal instead of a generic
     * keyword list.
     *
     * @return string[]
     */
    public function extractVocabulary(array $context): array
    {
        $vocabulary = [];
        foreach ($context['categories'] as $category) {
            // A normal category lists 'products'; a fixed-price menu has
            // none — its dishes are in its 'sections'.
            $dishes = $category['products']
                ?? array_merge([], ...array_column($category['sections'] ?? [], 'dishes'));
            foreach ($dishes as $dish) {
                foreach (is_array($dish['labels']) ? $dish['labels'] : [] as $name) {
                    $vocabulary[$name] = true;
                }
                foreach (is_array($dish['allergens']) ? $dish['allergens'] : [] as $names) {
                    foreach ($names as $name) {
                        $vocabulary[$name] = true;
                    }
                }
            }
        }

        return array_keys($vocabulary);
    }

    /**
     * @param bool $isSetMenuDish When true, this dish belongs to a fixed-price
     *   menu category — its own standalone price and price_variants are
     *   omitted (it shares the menu's one price, see MenuContextBuilder::build()'s
     *   'menu_price'), replaced by 'part_of_set_menu' + 'supplement' (only
     *   ever an extra charge on top of the menu price, never a full price).
     */
    private function buildProduct(Product $product, string $locale, Restaurant $restaurant, string $currency, array $allergenEntries, \Closure $ingredientDictionary, bool $isSetMenuDish = false): array
    {
        // Same fallback as the menu (show.html.twig / _set_menu.html.twig):
        // locale, default language, then whatever translation it has.
        $translation = $product->getTranslation($locale)
            ?? $product->getTranslation($restaurant->getDefaultLanguage())
            ?? ($product->getTranslations()->first() ?: null);

        $otherTags = [];
        $highlighted = [];
        foreach ($product->getTags() as $tag) {
            if ($tag->isSystem() && in_array($tag->getCode(), self::KNOWN_HIGHLIGHT_CODES, true)) {
                $highlighted[] = $tag->getCode();
                continue;
            }
            $otherTags[] = $tag;
        }

        $ingredientNames = $product->getIngredientNames($locale, $restaurant->getDefaultLanguage(), $ingredientDictionary);

        $safety = $this->dishSafety->shape(
            $otherTags,
            $allergenEntries,
            fn (ProductTag $tag) => $this->tagName($tag, $locale, $restaurant),
            fn (Allergen $allergen) => $this->allergenName($allergen, $locale, $restaurant),
            fn () => $this->allergensByCode ??= $this->allergenRepository->findAllIndexedByCode(),
        );

        $base = [
            'name' => $translation?->getName() ?? '',
            'description' => $translation?->getDescription(),
            'calories' => $product->getCalories(),
            'spicy_level' => self::spiceWords($product->getSpicyLevel()),
            'ingredients' => $ingredientNames,
            ...$safety,
            'highlighted' => $highlighted,
        ];

        if ($isSetMenuDish) {
            $base['part_of_set_menu'] = true;
            $base['supplement'] = $product->getSupplementPrice() !== null
                ? $this->convertedDecimal($product->getSupplementPrice(), $restaurant, $currency)
                : null;

            return $base;
        }

        $priceVariants = $product->hasPriceVariants()
            ? array_merge(
                [['label' => $product->getBasePriceLabel(), 'price' => $this->convertedDecimal($product->getBasePrice(), $restaurant, $currency)]],
                array_map(
                    fn (ProductPriceVariant $v) => ['label' => $v->getLabel(), 'price' => $this->convertedDecimal($v->getPrice(), $restaurant, $currency)],
                    $product->getPriceVariants()->toArray()
                )
            )
            : null;

        $base['price'] = $this->convertedDecimal($product->getBasePrice(), $restaurant, $currency);
        $base['price_variants'] = $priceVariants;

        return $base;
    }

    /** Converts an amount in cents from the restaurant's base currency into $currency, as a decimal. */
    private function convertedDecimal(int $amountInCents, Restaurant $restaurant, string $currency): float
    {
        return $this->currencyConverter->convert($amountInCents, $restaurant->getCurrency(), $currency) / 100;
    }

    private function categoryName(Category $category, string $locale, Restaurant $restaurant): string
    {
        // Same fallback as the menu's section headings (show.html.twig).
        $t = $category->getTranslation($locale)
            ?? $category->getTranslation($restaurant->getDefaultLanguage())
            ?? ($category->getTranslations()->first() ?: null);

        return $t?->getName() ?? '';
    }

    private function tagName(ProductTag $tag, string $locale, Restaurant $restaurant): string
    {
        $t = $tag->getTranslation($locale)
            ?? $tag->getTranslation($restaurant->getDefaultLanguage())
            ?? $tag->getTranslation('en');

        return $t?->getName() ?? $tag->getCode();
    }

    private function allergenName(Allergen $allergen, string $locale, Restaurant $restaurant): string
    {
        $t = $allergen->getTranslation($locale)
            ?? $allergen->getTranslation($restaurant->getDefaultLanguage())
            ?? $allergen->getTranslation('en');

        return $t?->getName() ?? $allergen->getCode();
    }

    private static function spiceWords(?int $level): ?string
    {
        if ($level === null || $level < 1) {
            return null;
        }

        return self::SPICE_WORDS[min($level, 5)];
    }
}
