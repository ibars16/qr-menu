<?php

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use PHPUnit\Framework\TestCase;

/**
 * getDisplayTranslation() exists so admin screens can still show *a* name
 * when Restaurant::$defaultLanguage doesn't match any translation the
 * category/product actually has — e.g. the default language was changed
 * after import — instead of "(sin nombre)" even though other locales have
 * a perfectly good name. getTranslation() itself must stay a strict exact
 * match: callers that decide whether to AI-translate a locale rely on it
 * returning null for "truly missing".
 */
final class DisplayTranslationFallbackTest extends TestCase
{
    private function categoryTranslation(string $locale, string $name, string $source = CategoryTranslation::SOURCE_HUMAN): CategoryTranslation
    {
        $t = new CategoryTranslation();
        $t->setLocale($locale);
        $t->setName($name);
        $t->setSource($source);

        return $t;
    }

    private function productTranslation(string $locale, string $name, string $source = ProductTranslation::SOURCE_HUMAN): ProductTranslation
    {
        $t = new ProductTranslation();
        $t->setLocale($locale);
        $t->setName($name);
        $t->setSource($source);

        return $t;
    }

    public function testCategoryReturnsExactLocaleMatchWhenPresent(): void
    {
        $category = new Category();
        $category->addTranslation($this->categoryTranslation('en', 'Pasta'));
        $category->addTranslation($this->categoryTranslation('es', 'Pasta ES'));

        self::assertSame('Pasta ES', $category->getDisplayTranslation('es')->getName());
    }

    public function testCategoryFallsBackToHumanTranslationWhenPreferredLocaleMissing(): void
    {
        $category = new Category();
        $category->addTranslation($this->categoryTranslation('en', 'Pasta'));
        $category->addTranslation($this->categoryTranslation('es', 'Pasta ES', CategoryTranslation::SOURCE_AI));

        // 'pl' doesn't exist — should prefer the human 'en' row over the AI 'es' one.
        self::assertSame('Pasta', $category->getDisplayTranslation('pl')->getName());
    }

    public function testCategoryFallsBackToAiTranslationWhenNoHumanOneExists(): void
    {
        $category = new Category();
        $category->addTranslation($this->categoryTranslation('es', 'Pasta ES', CategoryTranslation::SOURCE_AI));

        self::assertSame('Pasta ES', $category->getDisplayTranslation('pl')->getName());
    }

    public function testCategoryReturnsNullWhenNoTranslationsExistAtAll(): void
    {
        $category = new Category();

        self::assertNull($category->getDisplayTranslation('pl'));
    }

    public function testProductFallsBackToHumanTranslationWhenPreferredLocaleMissing(): void
    {
        $product = new Product();
        $product->addTranslation($this->productTranslation('en', 'Carpaccio'));
        $product->addTranslation($this->productTranslation('es', 'Carpaccio ES', ProductTranslation::SOURCE_AI));

        self::assertSame('Carpaccio', $product->getDisplayTranslation('pl')->getName());
    }

    public function testProductReturnsNullWhenNoTranslationsExistAtAll(): void
    {
        $product = new Product();

        self::assertNull($product->getDisplayTranslation('pl'));
    }
}
