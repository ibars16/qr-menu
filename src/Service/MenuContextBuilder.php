<?php

namespace App\Service;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTag;
use App\Entity\Restaurant;

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

    public function __construct(
        private readonly ProductAllergenResolver $allergenResolver,
        private readonly CurrencyConverter $currencyConverter,
    ) {}

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
        $categories = $restaurant->getCategories()
            ->filter(fn (Category $c) => $c->isActive())
            ->toArray();
        usort($categories, fn (Category $a, Category $b) => $a->getPosition() <=> $b->getPosition());

        $allProducts = [];
        foreach ($categories as $category) {
            foreach ($category->getProducts() as $product) {
                if ($product->isActive() && $product->isSafeToDisplay()) {
                    $allProducts[] = $product;
                }
            }
        }
        $allergensByProduct = $this->allergenResolver->resolveForProducts($allProducts);

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
                            fn (Product $p) => $this->buildProduct($p, $locale, $restaurant, $currency, $allergensByProduct[$p->getId()] ?? [], true),
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

            $products = $category->getProducts()
                ->filter(fn (Product $p) => $p->isActive() && $p->isSafeToDisplay())
                ->toArray();
            usort($products, fn (Product $a, Product $b) => $a->getPosition() <=> $b->getPosition());

            if (empty($products)) {
                continue;
            }

            $result['categories'][] = [
                'name' => $this->categoryName($category, $locale, $restaurant),
                'type' => $category->getType()?->value,
                'products' => array_map(
                    fn (Product $p) => $this->buildProduct($p, $locale, $restaurant, $currency, $allergensByProduct[$p->getId()] ?? []),
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
            foreach ($category['products'] as $product) {
                foreach ($product['dietary_tags'] as $name) {
                    $vocabulary[$name] = true;
                }
                foreach ($product['allergens'] as $allergen) {
                    $vocabulary[$allergen['name']] = true;
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
    private function buildProduct(Product $product, string $locale, Restaurant $restaurant, string $currency, array $allergenEntries, bool $isSetMenuDish = false): array
    {
        $translation = $product->getTranslation($locale)
            ?? $product->getTranslation($restaurant->getDefaultLanguage())
            ?? $product->getTranslation('en');

        $dietaryTags = [];
        $highlighted = [];
        foreach ($product->getTags() as $tag) {
            if ($tag->isSystem() && in_array($tag->getCode(), self::KNOWN_HIGHLIGHT_CODES, true)) {
                $highlighted[] = $tag->getCode();
                continue;
            }
            $dietaryTags[] = $this->tagName($tag, $locale, $restaurant);
        }

        $ingredientNames = [];
        foreach ($product->getIngredients() as $ingredient) {
            $t = $ingredient->getTranslation($locale)
                ?? $ingredient->getTranslation($restaurant->getDefaultLanguage())
                ?? $ingredient->getTranslation('en');
            if ($t) {
                $ingredientNames[] = $t->getName();
            }
        }
        foreach ($product->getGlobalIngredients() as $globalIngredient) {
            $t = $globalIngredient->getTranslation($locale)
                ?? $globalIngredient->getTranslation($restaurant->getDefaultLanguage())
                ?? $globalIngredient->getTranslation('en');
            if ($t) {
                $ingredientNames[] = $t->getName();
            }
        }

        $allergens = array_map(fn (array $entry) => [
            'name' => $this->allergenName($entry['allergen'], $locale, $restaurant),
            'presence' => $entry['presence']->value,
            'note' => $entry['note'],
        ], $allergenEntries);

        $base = [
            'name' => $translation?->getName() ?? '',
            'description' => $translation?->getDescription(),
            'calories' => $product->getCalories(),
            'spicy_level' => $product->getSpicyLevel(),
            'ingredients' => $ingredientNames,
            'dietary_tags' => $dietaryTags,
            'allergens' => $allergens,
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
        $t = $category->getTranslation($locale)
            ?? $category->getTranslation($restaurant->getDefaultLanguage())
            ?? $category->getTranslation('en');

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
}
