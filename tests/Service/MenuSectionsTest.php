<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\MenuSection;
use App\Entity\Product;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the (b)-shape menu structure — Product::$menuSection
 * as the source of truth (replacing the earlier shared-position-scale
 * approach) — via MenuSection::getProductsSorted() and
 * Category::getSectionsWithProducts(), the two methods behind the Menús
 * edit screen. No DB/kernel needed: everything here is a plain, unpersisted
 * entity graph.
 */
final class MenuSectionsTest extends TestCase
{
    private function product(int $position): Product
    {
        $product = new Product();
        $product->setPosition($position);
        $product->setBasePrice(1000);

        return $product;
    }

    private function section(int $position, string $label = 'Section'): MenuSection
    {
        $section = new MenuSection();
        $section->setLabel($label);
        $section->setPosition($position);

        return $section;
    }

    public function testSectionReturnsItsOwnProductsInPositionOrder(): void
    {
        $section = $this->section(0);
        $p2 = $this->product(2);
        $p0 = $this->product(0);
        $p1 = $this->product(1);
        $section->addProduct($p2);
        $section->addProduct($p0);
        $section->addProduct($p1);

        self::assertSame([$p0, $p1, $p2], $section->getProductsSorted());
    }

    public function testAddingAProductSetsItsMenuSection(): void
    {
        $section = $this->section(0);
        $product = $this->product(0);

        $section->addProduct($product);

        self::assertSame($section, $product->getMenuSection());
    }

    public function testCategoryReturnsSectionsInPositionOrderEachWithItsOwnProducts(): void
    {
        $category = new Category();

        $mains = $this->section(1, 'Segundos');
        $starters = $this->section(0, 'Primeros');

        $soup = $this->product(0);
        $starters->addProduct($soup);

        $steak = $this->product(1);
        $fish = $this->product(0);
        $mains->addProduct($steak);
        $mains->addProduct($fish);

        // Added out of order — must not rely on collection insertion order.
        $category->addMenuSection($mains);
        $category->addMenuSection($starters);

        $rows = $category->getSectionsWithProducts();

        self::assertSame($starters, $rows[0]['section']);
        self::assertSame([$soup], $rows[0]['products']);
        self::assertSame($mains, $rows[1]['section']);
        self::assertSame([$fish, $steak], $rows[1]['products']);
    }

    public function testProductPositionIsScopedPerSectionNotPerCategory(): void
    {
        // Two sections can each have a product at position 0 — position no
        // longer shares a single scale across the whole category (that was
        // the old (a) approach; see MenuSection's class docblock).
        $category = new Category();
        $sectionA = $this->section(0, 'A');
        $sectionB = $this->section(1, 'B');
        $productA = $this->product(0);
        $productB = $this->product(0);
        $sectionA->addProduct($productA);
        $sectionB->addProduct($productB);
        $category->addMenuSection($sectionA);
        $category->addMenuSection($sectionB);

        $rows = $category->getSectionsWithProducts();

        self::assertSame([$productA], $rows[0]['products']);
        self::assertSame([$productB], $rows[1]['products']);
    }

    public function testEmptySectionHasNoProducts(): void
    {
        $category = new Category();
        $category->addMenuSection($this->section(0, 'Postres'));

        $rows = $category->getSectionsWithProducts();

        self::assertSame([], $rows[0]['products']);
    }
}
