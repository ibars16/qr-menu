<?php

namespace App\Tests\Entity;

use App\Entity\Product;
use PHPUnit\Framework\TestCase;

/**
 * Data-layer coverage for the four nutrition fields added in
 * Version20260908043848 (Fase 1 of 3 — data layer only, see project
 * memory). No admin/template work exists yet to exercise these through;
 * this just pins down the entity contract Fase 2/3 will build on:
 * - all four independently nullable, no cross-field requirement
 * - decimal (NUMERIC(5,1)) round-trips as a string, not a float, since that
 *   is the whole point of choosing decimal over float for hand-typed,
 *   displayed-verbatim values (see the entity's own docblock) — a getter
 *   returning float here would silently defeat that choice.
 */
final class ProductNutritionTest extends TestCase
{
    public function testAllFourNutritionFieldsDefaultToNull(): void
    {
        $product = new Product();

        self::assertNull($product->getFat());
        self::assertNull($product->getProtein());
        self::assertNull($product->getCarbohydrates());
        self::assertNull($product->getSugars());
    }

    public function testEachNutritionFieldCanBeSetIndependentlyOfTheOthers(): void
    {
        $product = new Product();

        $product->setFat('6.5');

        self::assertSame('6.5', $product->getFat());
        self::assertNull($product->getProtein(), 'setting fat must not affect protein');
        self::assertNull($product->getCarbohydrates(), 'setting fat must not affect carbohydrates');
        self::assertNull($product->getSugars(), 'setting fat must not affect sugars');
    }

    public function testGettersReturnStringNotFloatToPreserveTheTypedDecimal(): void
    {
        $product = new Product();
        $product->setProtein('12.0');

        // Deliberately asserting the exact string, not just the numeric
        // value — a trailing ".0" is the whole reason decimal was chosen
        // over float, and a wrong getter signature (?float) would collapse
        // '12.0' and 12.0 to the same assertion pass here while actually
        // breaking Fase 3's verbatim display.
        self::assertIsString($product->getProtein());
        self::assertSame('12.0', $product->getProtein());
    }

    public function testFieldCanBeClearedBackToNull(): void
    {
        $product = new Product();
        $product->setSugars('3.2');
        self::assertSame('3.2', $product->getSugars());

        $product->setSugars(null);
        self::assertNull($product->getSugars());
    }

    public function testCaloriesIsUntouchedByTheNewFields(): void
    {
        // calories predates this migration (?int, whole kcal) — this
        // migration must not have changed its type or introduced any
        // interaction with it.
        $product = new Product();
        $product->setCalories(210);
        $product->setFat('6.5');
        $product->setCarbohydrates('20.0');

        self::assertSame(210, $product->getCalories());
        self::assertIsInt($product->getCalories());
    }
}
