<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\Ingredient;
use App\Entity\Restaurant;

class MenuContextBuilder
{
    public function build(Restaurant $restaurant): array
    {
        $context = [
            'restaurant' => [
                'id' => $restaurant->getId(),
                'name' => $restaurant->getName(),
                'slug' => $restaurant->getSlug(),
                'currency' => $restaurant->getCurrency(),
                'defaultLanguage' => $restaurant->getDefaultLanguage(),
            ],
            'products' => [],
        ];

        foreach ($restaurant->getCategories() as $category) {

            if (!$category->isActive()) {
                continue;
            }

            foreach ($category->getProducts() as $product) {

                if (!$product->isActive()) {
                    continue;
                }

                $context['products'][] = $this->buildProduct(
                    $product,
                    $category
                );
            }
        }

        return $context;
    }

    private function buildProduct(
        Product $product,
        Category $category
    ): array {

        $translations = [];

        foreach ($product->getTranslations() as $translation) {

            $translations[$translation->getLocale()] = [
                'name' => $translation->getName(),
                'description' => $translation->getDescription(),
            ];
        }

        $tags = [];

        /** @var ProductTag $tag */
        foreach ($product->getTags() as $tag) {

            $tags[] = [
                'id' => $tag->getId(),
                'code' => $tag->getCode(),
                'icon' => $tag->getIcon(),
            ];
        }

        $ingredients = [];

        /** @var Ingredient $ingredient */
        foreach ($product->getIngredients() as $ingredient) {

            $ingredients[] = [
                'id' => $ingredient->getId(),
                'code' => $ingredient->getCode(),
            ];
        }

        return [
            'id' => $product->getId(),

            'category' => [
                'id' => $category->getId(),
                'translations' => $this->buildCategoryTranslations($category),
            ],

            'price' => $product->getBasePriceDecimal(),

            'calories' => $product->getCalories(),

            'spicyLevel' => $product->getSpicyLevel(),

            'translations' => $translations,

            'tags' => $tags,

            'ingredients' => $ingredients,
        ];
    }

    private function buildCategoryTranslations(
        Category $category
    ): array
    {
        $translations = [];

        foreach ($category->getTranslations() as $translation) {

            $translations[$translation->getLocale()] = [
                'name' => $translation->getName(),
            ];
        }

        return $translations;
    }
}
