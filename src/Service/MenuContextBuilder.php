<?php

namespace App\Service;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\Product;
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
    ) {}

    public function build(Restaurant $restaurant, string $locale): array
    {
        $categories = $restaurant->getCategories()
            ->filter(fn (Category $c) => $c->isActive())
            ->toArray();
        usort($categories, fn (Category $a, Category $b) => $a->getPosition() <=> $b->getPosition());

        $allProducts = [];
        foreach ($categories as $category) {
            foreach ($category->getProducts() as $product) {
                if ($product->isActive()) {
                    $allProducts[] = $product;
                }
            }
        }
        $allergensByProduct = $this->allergenResolver->resolveForProducts($allProducts);

        $result = [
            'restaurant_name' => $restaurant->getName(),
            'currency' => $restaurant->getCurrency(),
            'categories' => [],
        ];

        foreach ($categories as $category) {
            $products = $category->getProducts()
                ->filter(fn (Product $p) => $p->isActive())
                ->toArray();
            usort($products, fn (Product $a, Product $b) => $a->getPosition() <=> $b->getPosition());

            if (empty($products)) {
                continue;
            }

            $result['categories'][] = [
                'name' => $this->categoryName($category, $locale, $restaurant),
                'products' => array_map(
                    fn (Product $p) => $this->buildProduct($p, $locale, $restaurant, $allergensByProduct[$p->getId()] ?? []),
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

    private function buildProduct(Product $product, string $locale, Restaurant $restaurant, array $allergenEntries): array
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

        return [
            'name' => $translation?->getName() ?? '',
            'description' => $translation?->getDescription(),
            'price' => $product->getBasePriceDecimal(),
            'calories' => $product->getCalories(),
            'spicy_level' => $product->getSpicyLevel(),
            'ingredients' => $ingredientNames,
            'dietary_tags' => $dietaryTags,
            'allergens' => $allergens,
            'highlighted' => $highlighted,
        ];
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
