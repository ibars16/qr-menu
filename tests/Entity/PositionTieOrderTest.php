<?php

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use PHPUnit\Framework\TestCase;

/**
 * Two dishes can share a position: a new one gets count() as its position
 * and deletes don't renumber. Then the order is fixed by id (oldest first),
 * whatever order they were loaded in.
 */
final class PositionTieOrderTest extends TestCase
{
    private static function withId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private static function product(int $id, int $position): Product
    {
        $product = new Product();
        self::withId($product, $id);
        $product->setBasePrice(1000);
        $product->setActive(true);
        $product->setPosition($position);
        $t = new ProductTranslation();
        $t->setLocale('es');
        $t->setName('Plato ' . $id);
        $product->addTranslation($t);

        return $product;
    }

    public function testDishesInTheSamePositionGoByIdWhateverTheLoadOrder(): void
    {
        foreach ([[30, 10, 20], [20, 30, 10], [10, 20, 30]] as $loadOrder) {
            $restaurant = new Restaurant();
            $restaurant->setDefaultLanguage('es');
            $category = new Category();
            $category->setRestaurant($restaurant);
            $t = new CategoryTranslation();
            $t->setLocale('es');
            $t->setName('Categoría');
            $category->addTranslation($t);

            $positions = [10 => 1, 20 => 1, 30 => 0];
            foreach ($loadOrder as $id) {
                $product = self::product($id, $positions[$id]);
                $product->setCategory($category);
                $category->getProducts()->add($product);
            }

            self::assertSame([30, 10, 20], array_map(static fn (Product $p) => $p->getId(), $category->getActiveProductsSorted()), 'load order ' . implode(',', $loadOrder));
        }
    }
}
