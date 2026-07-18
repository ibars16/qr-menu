<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Enum\CategoryType;
use App\Service\CategoryTypeFilterResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the public menu's food/drink filter — whether it
 * should render at all, and whether a given category matches the
 * customer's active selection. See CategoryTypeFilterResolver's docblock
 * for why matchesFilter()'s rule is also duplicated in
 * menu/_search_js.html.twig (the browser can't call PHP); no DB/kernel
 * needed here since Category::$type is a plain property, not persisted.
 */
final class CategoryTypeFilterResolverTest extends TestCase
{
    private CategoryTypeFilterResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CategoryTypeFilterResolver();
    }

    private function category(?CategoryType $type): Category
    {
        $category = new Category();
        $category->setType($type);

        return $category;
    }

    public function testFilterHiddenWithOnlyFoodCategories(): void
    {
        $categories = [$this->category(CategoryType::Food), $this->category(CategoryType::Food), $this->category(null)];

        self::assertFalse($this->resolver->shouldShowFilter($categories));
    }

    public function testFilterHiddenWithOnlyDrinkCategories(): void
    {
        $categories = [$this->category(CategoryType::Drink), $this->category(null)];

        self::assertFalse($this->resolver->shouldShowFilter($categories));
    }

    public function testFilterHiddenWithOnlyUnclassifiedCategories(): void
    {
        $categories = [$this->category(null), $this->category(null)];

        self::assertFalse($this->resolver->shouldShowFilter($categories));
    }

    public function testFilterShownWithBothFoodAndDrinkCategories(): void
    {
        $categories = [$this->category(CategoryType::Food), $this->category(CategoryType::Drink), $this->category(null)];

        self::assertTrue($this->resolver->shouldShowFilter($categories));
    }

    public function testUnclassifiedCategoryMatchesEveryFilterMode(): void
    {
        self::assertTrue($this->resolver->matchesFilter(null, 'all'));
        self::assertTrue($this->resolver->matchesFilter(null, 'food'));
        self::assertTrue($this->resolver->matchesFilter(null, 'drink'));
    }

    public function testClassifiedCategoryOnlyMatchesItsOwnModeOrAll(): void
    {
        self::assertTrue($this->resolver->matchesFilter(CategoryType::Food, 'all'));
        self::assertTrue($this->resolver->matchesFilter(CategoryType::Food, 'food'));
        self::assertFalse($this->resolver->matchesFilter(CategoryType::Food, 'drink'));

        self::assertTrue($this->resolver->matchesFilter(CategoryType::Drink, 'all'));
        self::assertTrue($this->resolver->matchesFilter(CategoryType::Drink, 'drink'));
        self::assertFalse($this->resolver->matchesFilter(CategoryType::Drink, 'food'));
    }
}
