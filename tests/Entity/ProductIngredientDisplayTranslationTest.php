<?php

namespace App\Tests\Entity;

use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\Product;
use PHPUnit\Framework\TestCase;

/**
 * Product::ingredientDisplayTranslation() — the one name fallback for an
 * ingredient, shared by the public menu (getIngredientNames()) and the
 * admin dish editor (MenuAdminController::getProduct(), with the panel
 * language first and the menu language second). Before, the admin stopped
 * at the menu language for a restaurant's own ingredient (no English, no
 * "any"), so one named only in English showed as its raw code there.
 */
final class ProductIngredientDisplayTranslationTest extends TestCase
{
    /** @param array<string, string> $names locale → name */
    private function ingredient(array $names): Ingredient
    {
        $ingredient = new Ingredient();
        $ingredient->setCode('ing');
        foreach ($names as $locale => $name) {
            $t = new IngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setIngredient($ingredient);
            $ingredient->addTranslation($t);
        }

        return $ingredient;
    }

    /** @param array<string, string> $names locale → name */
    private function globalIngredient(array $names): GlobalIngredient
    {
        $ingredient = new GlobalIngredient();
        $ingredient->setCode('ing');
        foreach ($names as $locale => $name) {
            $t = new GlobalIngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setGlobalIngredient($ingredient);
            $ingredient->addTranslation($t);
        }

        return $ingredient;
    }

    public function testRequestedLocaleThenFallbackThenEnglishThenAny(): void
    {
        $all = ['fr' => 'Tomate FR', 'en' => 'Tomato', 'es' => 'Tomate', 'de' => 'Tomate DE'];

        self::assertSame('Tomate DE', Product::ingredientDisplayTranslation($this->ingredient($all), 'de', 'es')->getName());
        self::assertSame('Tomate', Product::ingredientDisplayTranslation($this->ingredient(['fr' => 'Tomate FR', 'en' => 'Tomato', 'es' => 'Tomate']), 'de', 'es')->getName());
        self::assertSame('Tomato', Product::ingredientDisplayTranslation($this->ingredient(['fr' => 'Tomate FR', 'en' => 'Tomato']), 'de', 'es')->getName());
        self::assertSame('Tomate FR', Product::ingredientDisplayTranslation($this->ingredient(['fr' => 'Tomate FR']), 'de', 'es')->getName());
    }

    public function testAnyNameSkipsBlankOnes(): void
    {
        self::assertSame('Pomodoro', Product::ingredientDisplayTranslation($this->ingredient(['fr' => ' ', 'it' => 'Pomodoro']), 'de', 'es')->getName());
    }

    public function testNoTranslationAtAllIsNull(): void
    {
        self::assertNull(Product::ingredientDisplayTranslation($this->ingredient([]), 'de', 'es'));
    }

    public function testGlobalIngredientsFollowTheSameOrder(): void
    {
        self::assertSame('Tomato', Product::ingredientDisplayTranslation($this->globalIngredient(['fr' => 'Tomate FR', 'en' => 'Tomato']), 'de', 'es')->getName());
        self::assertSame('Tomate FR', Product::ingredientDisplayTranslation($this->globalIngredient(['fr' => 'Tomate FR']), 'de', 'es')->getName());
    }
}
