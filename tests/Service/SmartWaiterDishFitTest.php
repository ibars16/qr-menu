<?php

namespace App\Tests\Service;

use App\Service\CustomerNeeds;
use App\Service\SmartWaiterDishFit;
use App\Service\SmartWaiterDishSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SmartWaiterDishFit sorts every dish for one customer — deterministic, no
 * AI: not_suitable (an avoided allergen is contains / may_contain, or the
 * diet is contradicted), suitable (everything confirmed), not_confirmed
 * (anything else, with what is unknown for them). Dishes here are hand-built in MenuContextBuilder's
 * shape, with SmartWaiterDishSafety's internal FIT_KEY.
 */
final class SmartWaiterDishFitTest extends TestCase
{
    /**
     * @param array<string, string> $allergens code => presence
     * @param list<string>          $diets     system diet codes kept as labels
     */
    private static function dish(string $name, array $allergens = [], array $diets = [], array $extra = []): array
    {
        $fitAllergens = [];
        foreach ($allergens as $code => $presence) {
            $fitAllergens[$code] = ['presence' => $presence, 'name' => ucfirst($code)];
        }

        return ['name' => $name, 'price' => 10.0, 'labels' => $diets === [] ? 'none' : $diets, 'allergens' => $allergens === [] ? '?' : ['contains' => array_keys($allergens)]]
            + $extra
            + [SmartWaiterDishSafety::FIT_KEY => ['allergens' => $fitAllergens, 'diets' => $diets]];
    }

    private static function context(array ...$dishes): array
    {
        return ['restaurant_name' => 'Casa Test', 'currency' => 'EUR', 'categories' => [['name' => 'Platos', 'type' => null, 'products' => $dishes]]];
    }

    /** @return string 'suitable' | 'not_confirmed' | 'not_suitable' — the group the one dish ended in */
    private static function groupOf(array $dish, CustomerNeeds $needs): string
    {
        $category = (new SmartWaiterDishFit())->apply(self::context($dish), $needs)['categories'][0];
        $groups = array_values(array_intersect(array_keys($category), [SmartWaiterDishFit::SUITABLE, SmartWaiterDishFit::NOT_CONFIRMED, SmartWaiterDishFit::NOT_SUITABLE]));
        self::assertCount(1, $groups);

        return $groups[0];
    }

    /** @return iterable<string, array{0: array, 1: CustomerNeeds, 2: string}> */
    public static function cases(): iterable
    {
        $gluten = new CustomerNeeds(['gluten'], [], true);

        yield 'contains the allergen' => [self::dish('Pizza', ['gluten' => 'contains']), $gluten, 'not_suitable'];
        yield 'may contain the allergen' => [self::dish('Pizza', ['gluten' => 'may_contain']), $gluten, 'not_suitable'];
        yield 'free from the allergen' => [self::dish('Ensalada', ['gluten' => 'free_from']), $gluten, 'suitable'];
        yield 'its "gluten-free" label' => [self::dish('Ensalada', [], ['gluten-free']), $gluten, 'suitable'];
        yield 'no allergen data at all ("?")' => [self::dish('Carpaccio'), $gluten, 'not_confirmed'];
        yield 'only other allergens known' => [self::dish('Flan', ['milk' => 'contains']), $gluten, 'not_confirmed'];
        yield 'two avoided, one confirmed only' => [self::dish('Sopa', ['gluten' => 'free_from']), new CustomerNeeds(['gluten', 'milk']), 'not_confirmed'];
        yield 'two avoided, both confirmed' => [self::dish('Sopa', ['gluten' => 'free_from', 'milk' => 'free_from']), new CustomerNeeds(['gluten', 'milk']), 'suitable'];
        yield 'vegan + contains milk' => [self::dish('Pizza', ['milk' => 'contains']), new CustomerNeeds([], ['vegan']), 'not_suitable'];
        yield 'vegan + contains fish' => [self::dish('Atún', ['fish' => 'contains']), new CustomerNeeds([], ['vegan']), 'not_suitable'];
        yield 'vegan + may contain milk: unconfirmed, not ruled out' => [self::dish('Pan', ['milk' => 'may_contain']), new CustomerNeeds([], ['vegan']), 'not_confirmed'];
        yield 'vegan label' => [self::dish('Hummus', [], ['vegan']), new CustomerNeeds([], ['vegan']), 'suitable'];
        yield 'vegan, no label' => [self::dish('Hummus'), new CustomerNeeds([], ['vegan']), 'not_confirmed'];
        yield 'vegetarian + contains crustaceans' => [self::dish('Paella', ['crustaceans' => 'contains']), new CustomerNeeds([], ['vegetarian']), 'not_suitable'];
        yield 'vegetarian + contains milk is fine' => [self::dish('Queso', ['milk' => 'contains'], ['vegetarian']), new CustomerNeeds([], ['vegetarian']), 'suitable'];
        yield 'halal only by its label' => [self::dish('Cordero', [], ['halal']), new CustomerNeeds([], ['halal']), 'suitable'];
        yield 'halal never ruled out by allergens' => [self::dish('Gambas', ['crustaceans' => 'contains']), new CustomerNeeds([], ['halal']), 'not_confirmed'];
        yield '"other": nothing is confirmed' => [self::dish('Ensalada', ['gluten' => 'free_from']), new CustomerNeeds(['gluten'], [], true, true), 'not_confirmed'];
        yield '"other" still rules out what it can' => [self::dish('Pizza', ['gluten' => 'contains']), new CustomerNeeds(['gluten'], [], true, true), 'not_suitable'];
    }

    #[DataProvider('cases')]
    public function testEachDishLandsInTheRightGroup(array $dish, CustomerNeeds $needs, string $expected): void
    {
        self::assertSame($expected, self::groupOf($dish, $needs));
    }

    public function testANotSuitableDishIsOnlyItsNameAndWhy(): void
    {
        $pizza = self::dish('Pizza de escalivada', ['gluten' => 'contains', 'milk' => 'contains', 'nuts' => 'may_contain'], ['vegetarian'], [
            'description' => 'Con escalivada', 'spicy_level' => 'spicy', 'highlighted' => ['recommended'], 'ingredients' => ['Pan'],
        ]);

        $sorted = (new SmartWaiterDishFit())->apply(self::context($pizza, self::dish('Pizza margarita', ['gluten' => 'contains'], [], ['spicy_level' => null])), new CustomerNeeds(['gluten', 'nuts'], ['vegan'], true));

        self::assertSame([
            ['name' => 'Pizza de escalivada', 'spicy' => true, 'contains' => ['Gluten'], 'may_contain' => ['Nuts'], 'not' => ['vegan']],
            ['name' => 'Pizza margarita', 'contains' => ['Gluten']],
        ], $sorted['categories'][0]['not_suitable'], '"spicy": true (only when it is), so "our only spicy dish has gluten" can be said — never the level, no price, description, ingredients or "highlighted"');
    }

    public function testANotConfirmedDishLosesABareQuestionMarkButKeepsWhatTheMenuLists(): void
    {
        $category = (new SmartWaiterDishFit())->apply(
            self::context(self::dish('Carpaccio'), self::dish('Flan', ['milk' => 'contains'])),
            new CustomerNeeds(['gluten'], [], true),
        )['categories'][0];

        self::assertArrayNotHasKey('allergens', $category['not_confirmed'][0], '"unknown_for_customer" already says it');
        self::assertSame(['gluten'], $category['not_confirmed'][0][SmartWaiterDishFit::UNKNOWN_KEY]);
        self::assertSame(['contains' => ['milk']], $category['not_confirmed'][1]['allergens'], '"does the flan have milk?" still answerable');
    }

    public function testWithoutNeedsTheQuestionMarkStays(): void
    {
        $products = (new SmartWaiterDishFit())->apply(self::context(self::dish('Carpaccio')), new CustomerNeeds())['categories'][0]['products'];

        self::assertSame('?', $products[0]['allergens']);
    }

    /** @return iterable<string, array{0: array, 1: CustomerNeeds, 2: list<string>}> */
    public static function unknowns(): iterable
    {
        yield 'no allergen data' => [self::dish('Carpaccio'), new CustomerNeeds(['gluten'], [], true), ['gluten']];
        yield 'other allergens listed, not the avoided one' => [self::dish('Flan', ['milk' => 'contains', 'eggs' => 'contains']), new CustomerNeeds(['gluten'], [], true), ['gluten']];
        yield 'one of two confirmed' => [self::dish('Sopa', ['gluten' => 'free_from']), new CustomerNeeds(['gluten', 'nuts']), ['tree nuts']];
        yield 'a diet without its label' => [self::dish('Hummus', ['gluten' => 'free_from']), new CustomerNeeds(['gluten'], ['vegan']), ['vegan']];
        yield '"other"' => [self::dish('Ensalada', ['gluten' => 'free_from']), new CustomerNeeds(['gluten'], [], true, true), [SmartWaiterDishFit::OTHER_UNKNOWN]];
    }

    #[DataProvider('unknowns')]
    public function testANotConfirmedDishSaysWhatIsUnknownForThisCustomer(array $dish, CustomerNeeds $needs, array $unknown): void
    {
        $sorted = (new SmartWaiterDishFit())->apply(self::context($dish), $needs)['categories'][0];

        self::assertSame($unknown, $sorted[SmartWaiterDishFit::NOT_CONFIRMED][0][SmartWaiterDishFit::UNKNOWN_KEY], 'a list of other allergens can\'t be read as "no gluten"');
    }

    public function testOnlyNotConfirmedDishesCarryTheUnknownList(): void
    {
        $json = json_encode((new SmartWaiterDishFit())->apply(self::context(self::dish('Sopa', ['gluten' => 'free_from']), self::dish('Pizza', ['gluten' => 'contains'])), new CustomerNeeds(['gluten'], [], true)));
        self::assertStringNotContainsString(SmartWaiterDishFit::UNKNOWN_KEY, $json);
        self::assertStringNotContainsString(SmartWaiterDishFit::UNKNOWN_KEY, json_encode((new SmartWaiterDishFit())->apply(self::context(self::dish('Carpaccio')), new CustomerNeeds())));
    }

    public function testHasSuitableLooksAtEveryCategoryAndSetMenuSection(): void
    {
        $fit = new SmartWaiterDishFit();
        $gluten = new CustomerNeeds(['gluten'], [], true);
        $setMenu = static fn (array $dish) => ['restaurant_name' => 'Casa Test', 'currency' => 'EUR', 'categories' => [
            ['name' => 'Platos', 'products' => [self::dish('Pizza', ['gluten' => 'contains'])]],
            ['name' => 'Menú del día', 'menu_price' => 15.0, 'sections' => [['label' => 'Primeros', 'dishes' => [$dish]]]],
        ]];

        self::assertTrue(SmartWaiterDishFit::hasSuitable($fit->apply($setMenu(self::dish('Sopa', ['gluten' => 'free_from'])), $gluten)));
        self::assertFalse(SmartWaiterDishFit::hasSuitable($fit->apply($setMenu(self::dish('Carpaccio')), $gluten)), 'not confirmed is not suitable');
        self::assertFalse(SmartWaiterDishFit::hasSuitable($fit->apply($setMenu(self::dish('Sopa', ['gluten' => 'free_from'])), new CustomerNeeds(['gluten'], [], true, true))), '"other": never');
    }

    public function testGroupsReplaceProductsInPlaceAndEmptyOnesAreLeftOut(): void
    {
        $sorted = (new SmartWaiterDishFit())->apply(
            self::context(self::dish('Pizza', ['gluten' => 'contains']), self::dish('Carpaccio'), self::dish('Croquetas')),
            new CustomerNeeds(['gluten'], [], true),
        );

        $category = $sorted['categories'][0];
        self::assertSame(['name', 'type', 'not_confirmed', 'not_suitable'], array_keys($category));
        self::assertSame(['gluten'], $category['not_confirmed'][0][SmartWaiterDishFit::UNKNOWN_KEY]);
        self::assertSame(['Carpaccio', 'Croquetas'], array_column($category['not_confirmed'], 'name'));
    }

    public function testSetMenuSectionsAreSortedTheSameWay(): void
    {
        $context = ['restaurant_name' => 'Casa Test', 'currency' => 'EUR', 'categories' => [[
            'name' => 'Menú del día', 'menu_price' => 15.0,
            'sections' => [['label' => 'Primeros', 'dishes' => [self::dish('Sopa', ['gluten' => 'free_from']), self::dish('Macarrones', ['gluten' => 'contains'])]]],
        ]]];

        $section = (new SmartWaiterDishFit())->apply($context, new CustomerNeeds(['gluten'], [], true))['categories'][0]['sections'][0];

        self::assertSame(['label', 'suitable', 'not_suitable'], array_keys($section));
        self::assertSame('Sopa', $section['suitable'][0]['name']);
        self::assertSame([['name' => 'Macarrones', 'contains' => ['Gluten']]], $section['not_suitable']);
    }

    public function testWithoutNeedsTheMenuIsExactlyAsBeforeMinusTheInternalKey(): void
    {
        $dishes = [self::dish('Pizza', ['gluten' => 'contains']), self::dish('Carpaccio')];

        $sorted = (new SmartWaiterDishFit())->apply(self::context(...$dishes), new CustomerNeeds());

        $expected = array_map(static function (array $d) {
            unset($d[SmartWaiterDishSafety::FIT_KEY]);

            return $d;
        }, $dishes);
        self::assertSame($expected, $sorted['categories'][0]['products']);
    }

    public function testTheInternalKeyNeverLeaves(): void
    {
        foreach ([new CustomerNeeds(), new CustomerNeeds(['gluten'], [], true)] as $needs) {
            $json = json_encode((new SmartWaiterDishFit())->apply(self::context(self::dish('Pizza', ['gluten' => 'contains']), self::dish('Sopa', ['gluten' => 'free_from'])), $needs));
            self::assertStringNotContainsString(SmartWaiterDishSafety::FIT_KEY, $json);
        }
    }
}
