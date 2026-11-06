<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Restaurant;

/**
 * Saves a hand-made order from the Carta's "Ordenar" mode.
 *
 * Each call receives the WHOLE list it reorders — every normal category of
 * the restaurant, or every dish of one category, hidden and pending-review
 * ones included — and refuses anything else: a missing, duplicated, foreign
 * or other-category id means the screen is stale (or the request is not
 * ours), so nothing is written. Positions are then a clean 0..n-1.
 *
 * Ordering is not reviewing: needsReview and import status are untouched.
 * Neither method flushes; the caller does (and bumps the menu version).
 */
final class CartaReorder
{
    /** @param mixed $ids the request's "ids" @return bool false = rejected, nothing changed */
    public function reorderCategories(Restaurant $restaurant, mixed $ids): bool
    {
        $categories = [];
        foreach ($restaurant->getCategories() as $category) {
            // Fixed-price menus have their own order on the Menús screen.
            if (!$category->isFixedPriceMenu()) {
                $categories[$category->getId()] = $category;
            }
        }

        $ordered = $this->match($categories, $ids);
        if ($ordered === null) {
            return false;
        }
        foreach ($ordered as $position => $category) {
            $category->setPosition($position);
        }

        return true;
    }

    /** @param mixed $ids the request's "ids" @return bool false = rejected, nothing changed */
    public function reorderProducts(Restaurant $restaurant, ?Category $category, mixed $ids): bool
    {
        if ($category === null || $category->getRestaurant() !== $restaurant || $category->isFixedPriceMenu()) {
            return false;
        }

        $products = [];
        foreach ($category->getProducts() as $product) {
            $products[$product->getId()] = $product;
        }

        $ordered = $this->match($products, $ids);
        if ($ordered === null) {
            return false;
        }
        foreach ($ordered as $position => $product) {
            $product->setPosition($position);
        }

        return true;
    }

    /**
     * @template T of Category|Product
     * @param array<int, T> $byId
     * @return list<T>|null the entities in the order of $ids, or null unless $ids is exactly $byId's keys, each once
     */
    private function match(array $byId, mixed $ids): ?array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) !== count($byId)) {
            return null;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (!is_int($id) || !isset($byId[$id])) {
                return null;
            }
            $ordered[] = $byId[$id];
            unset($byId[$id]);
        }

        return $ordered;
    }
}
