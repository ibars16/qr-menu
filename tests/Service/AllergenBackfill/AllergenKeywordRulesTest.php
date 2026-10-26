<?php

namespace App\Tests\Service\AllergenBackfill;

use App\Service\AllergenBackfill\AllergenKeywordRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllergenKeywordRulesTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, list<string>}> */
    public static function names(): iterable
    {
        yield 'egg variants'          => ['Free range egg white', ['eggs'], []];
        yield 'cheese, Spanish'       => ['Virutas de queso', ['milk'], []];
        yield 'accents are ignored'   => ['Gambón', ['crustaceans'], []];
        yield 'Catalan almond'        => ['ametllat', ['nuts'], []];
        yield 'coconut milk'          => ['Coconut milk', [], ['milk', 'nuts']];
        yield 'peanut butter'         => ['Peanut butter', ['peanuts'], ['milk']];
        yield 'almond milk'           => ['Almond milk', ['nuts'], ['milk']];
        yield 'oyster mushroom'       => ['Oyster mushroom', [], ['molluscs']];
        yield 'nutmeg'                => ['Nuez moscada', [], ['nuts']];
        yield 'rice flour'            => ['Harina de arroz', [], ['gluten']];
        yield 'maize semolina'        => ['Wholegrain maize semolina', [], ['gluten']];
        yield 'ginger beer'           => ['ginger beer', [], ['gluten']];
        yield 'egg pasta, two'        => ['Egg pasta', ['eggs', 'gluten'], []];
        yield 'wine'                  => ['Dry white wine', ['sulphites'], []];
        yield 'plain vegetable'       => ['Lechuga romana', [], []];
        yield 'teff flour'            => ['Ivory teff flour', [], ['gluten']];
        yield 'lentil flour'          => ['Wholemeal lentil flour', [], ['gluten']];
        yield 'cottonseed flour'      => ['Low fat cottonseed flour', [], ['gluten']];
        yield 'poultry oyster'        => ['Raw emu oyster', [], ['molluscs']];
        yield 'ostrich oyster'        => ['Raw ostrich oyster', [], ['molluscs']];
        yield 'ucuhuba butter'        => ['Ucuhuba butter oil', [], ['milk']];
        yield 'real oyster still is'  => ['Pacific oyster', ['molluscs'], []];
        yield 'wheat flour still is'  => ['Refined wheat flour', ['gluten'], []];
    }

    /**
     * @param list<string> $expected
     * @param list<string> $forbidden
     */
    #[DataProvider('names')]
    public function testEvaluate(string $name, array $expected, array $forbidden): void
    {
        $result = (new AllergenKeywordRules())->evaluate($name);

        sort($expected);
        sort($forbidden);
        $actualExpected = $result['expected'];
        $actualForbidden = $result['forbidden'];
        sort($actualExpected);
        sort($actualForbidden);

        self::assertSame($expected, $actualExpected, 'expected');
        self::assertSame($forbidden, $actualForbidden, 'forbidden');
    }

    /** @return iterable<string, array{string}> */
    public static function compositeNames(): iterable
    {
        foreach ([
            'Worcestershire sauce', 'Sweet and sour sauce', 'Salsa pesto', 'Chili mayonnaise', 'Galleta de chocolate', 'Butter biscuit',
            'CARAMELO', 'SIROPE DE CHOCOLATE', 'Wheat glucose-fructose syrup', 'Licor de saúco', 'Cream liqueur', 'Baking powder',
            'Levadura química', 'Wholemeal rye bread', 'Pan de molde', 'Ravioles caseros', 'Ñoquis caseros', 'Chorizo ibérico',
            'Nduja calabresa', 'Milk chocolate chips', 'Helado de vainilla', 'Vanilla ice cream', 'Egg flavour', 'Margarina',
            'Cream filling', 'Relleno de crema', 'Crust', 'Pizza crust', 'Corteza de pan', 'Amaretto', 'Advocaat', 'Baileys', 'Petit-Beurre',
            'Petit beurre', 'Galletas María', 'Speculoos', 'Cereal crispies', 'Cereal extrudate', 'Cereal flakes', 'Corn flakes', 'Bran',
            'Salvado', 'Pollo rebozado', 'Crispbread', 'Bastardo', 'Pepperoni', 'Pepperoni slices', 'Salami milano', 'Salchichón ibérico',
            'Pork sausage', 'Salchicha fresca', 'Mortadela', 'Mortadella di Bologna', 'Chorizo de Pamplona', 'Sobrasada', 'Sobrassada de Mallorca',
            'Paté de campaña', 'Pâté de foie', 'Chistorra', 'Longaniza', 'Bratwurst',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('compositeNames')]
    public function testCompositesAlwaysGoToReview(string $name): void
    {
        self::assertNotSame([], (new AllergenKeywordRules())->evaluate($name)['review']);
    }

    /** @return iterable<string, array{string}> */
    public static function simpleNames(): iterable
    {
        foreach (['Chicken egg', 'Plain yogurt', 'Atlantic salmon', 'Refined wheat flour', 'Lechuga romana', 'Jamón ibérico', 'Whole walnuts', 'Manteca de cerdo', 'Pure cocoa butter', 'Rice bran', 'Maize bran', 'Wheat bran', 'Oat bran', 'Salvado de arroz', 'Butter'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('simpleNames')]
    public function testSimpleIngredientsAreNotForcedToReview(string $name): void
    {
        self::assertSame([], (new AllergenKeywordRules())->evaluate($name)['review']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function processedNames(): iterable
    {
        yield 'extract'              => ['Yeast extract', 'extract'];
        yield 'extracto'             => ['Extracto de levadura', 'extracto'];
        yield 'powder'               => ['Garlic powder', 'powder'];
        yield 'en polvo'             => ['Ajo en polvo', 'polvo'];
        yield 'flakes'               => ['Potato flakes', 'flakes'];
        yield 'preparation'          => ['Powdered preparation with palm fat', 'powdered'];
        yield 'preparado'            => ['Preparado para flan', 'preparado'];
        yield 'mix'                  => ['Spice mix', 'mix'];
        yield 'seasoning'            => ['Taco seasoning', 'seasoning'];
        yield 'dried'                => ['Dried fig', 'dried'];
        yield 'deshidratado'         => ['Tomate deshidratado', 'deshidratado'];
        yield 'instant'              => ['Instant coffee', 'instant'];
        yield 'hydrolysed'           => ['Hydrolised animal protein', 'hydrolised'];
        yield 'starch'               => ['Modified starch', 'starch'];
        yield 'fibre'                => ['Citrus fibre', 'fibre'];
        yield 'puffed'               => ['quinoa inflada', 'inflada'];
        yield 'dried fig'            => ['Dried fig', 'dried'];
        yield 'raisins'              => ['Raisins', 'raisins'];
        yield 'orejones'             => ['Orejones de albaricoque', 'orejones'];
        yield 'pasas'                => ['Uvas pasas', 'pasas'];
        yield 'prunes'               => ['Prunes from Agen', 'prunes'];
        yield 'juice concentrate'    => ['Beetroot juice concentrate', 'concentrate'];
        yield 'puree, not a juice'   => ['Mango puree from concentrate', 'concentrate'];
        yield 'dried juice'          => ['Dried apple juice from concentrate', 'dried'];
        yield 'raw ham'              => ['Raw ham', 'ham'];
        yield 'jamón ibérico'        => ['Jamón ibérico de bellota', 'jamon'];
        yield 'jamón serrano'        => ['Jamón serrano', 'jamon'];
        yield 'pernil'               => ['Pernil curat', 'pernil'];
        yield 'prosciutto'           => ['Prosciutto crudo', 'prosciutto'];
        yield 'cecina'               => ['Cecina de León', 'cecina'];
        yield 'bacon'                => ['Smoked bacon', 'bacon'];
        yield 'paste'                => ['Horseradish paste', 'paste'];
        yield 'tomato paste'         => ['Tomato paste', 'paste'];
        yield 'pasta de'             => ['Pasta de curry rojo', 'pasta de'];
        yield 'pasta de aceitunas'   => ['Pasta de aceitunas negras', 'pasta de'];
        yield 'pasta di'             => ['Pasta di pistacchio', 'pasta di'];
        yield 'pâte'                 => ['Pâte de piment', 'pate'];
    }

    /** @return iterable<string, array{string}> */
    public static function foodPastaNames(): iterable
    {
        foreach (['Pasta fresca', 'Pasta de sémola', 'Pasta de sémola de trigo duro', 'Pasta di semola', 'Pasta de trigo', 'Pasta de arroz',
            'Pasta de huevo', 'Pasta de lentejas', 'Pasta', 'Spaghetti', 'Pasta de sopa'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('foodPastaNames')]
    public function testFoodPastaIsNotAPreparedPaste(string $name): void
    {
        self::assertNull((new AllergenKeywordRules())->evaluate($name)['processed']);
    }

    /** @return iterable<string, array{string}> */
    public static function juicesFromConcentrate(): iterable
    {
        foreach (['Orange juice from concentrate', 'Grapefruit juice based on concentrate', 'Organic orange juice from orange juice concentrate',
            'Mandarin juice from concentrate', 'Zumo de naranja a base de concentrado', 'Zumo de manzana de concentrado'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('juicesFromConcentrate')]
    public function testJuiceFromConcentrateIsNotProcessed(string $name): void
    {
        self::assertNull((new AllergenKeywordRules())->evaluate($name)['processed']);
    }

    #[DataProvider('processedNames')]
    public function testProcessedProductsAreDetected(string $name, string $keyword): void
    {
        self::assertSame($keyword, (new AllergenKeywordRules())->evaluate($name)['processed']);
    }

    public function testFreshProductsAreNotProcessed(): void
    {
        $rules = new AllergenKeywordRules();

        foreach (['Fresh basil', 'Raw cabbage', 'Chicken breast', 'Tomate', 'Lechuga romana', 'Apple'] as $name) {
            self::assertNull($rules->evaluate($name)['processed'], $name);
        }
    }

    public function testMantecaIsFlaggedAsPossibleMilk(): void
    {
        $review = (new AllergenKeywordRules())->evaluate('manteca')['review'];

        self::assertCount(1, $review);
        self::assertStringContainsString('mantequilla', $review[0]);
    }

    public function testVariantsOfOneBaseShareTheirRoot(): void
    {
        $rules = new AllergenKeywordRules();

        self::assertSame('egg', $rules->evaluate('Egg')['root']);
        self::assertSame('egg', $rules->evaluate('Free range eggs')['root']);
        self::assertSame('egg', $rules->evaluate('Chicken egg yolk')['root']);
    }
}
