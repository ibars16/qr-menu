<?php

namespace App\Tests\Service;

use App\Entity\Allergen;
use App\Entity\AllergenTranslation;
use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Repository\AllergenRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\OwnerTextTranslationRepository;
use App\Service\CurrencyConverter;
use App\Service\MenuContextBuilder;
use App\Service\OwnerTextTranslationService;
use App\Service\ProductAllergenResolver;
use App\Service\ProductTranslatorInterface;
use App\Service\SmartWaiterDishSafety;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

/**
 * What Smart Waiter is told about a dish's diet and allergens leaves the
 * model nothing to infer (SmartWaiterDishSafety, through MenuContextBuilder):
 * "labels" / "allergens" always say so when there's none, a "contains-…"
 * tag is an allergen, a diet tag the allergens contradict is left out — and
 * a fixed-price menu's dishes are read too (extractVocabulary()).
 *
 * No kernel/DB. Fixture products are unpersisted, so ProductAllergenResolver
 * returns no entries for them (its empty-id early return); allergen entries
 * are therefore exercised on SmartWaiterDishSafety directly.
 */
final class MenuContextBuilderSmartWaiterDataTest extends TestCase
{
    /** @var array<string, Allergen> */
    private array $allergens = [];

    protected function setUp(): void
    {
        $position = 0;
        foreach (['gluten' => 'Gluten', 'eggs' => 'Huevo', 'fish' => 'Pescado', 'milk' => 'Leche', 'nuts' => 'Frutos de cáscara', 'molluscs' => 'Moluscos'] as $code => $name) {
            $allergen = new Allergen();
            $allergen->setCode($code);
            $allergen->setPosition($position++);
            $t = new AllergenTranslation();
            $t->setLocale('es');
            $t->setName($name);
            $allergen->addTranslation($t);
            $this->allergens[$code] = $allergen;
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function restaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Test');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');

        return $restaurant;
    }

    private function category(Restaurant $restaurant, string $name, ?int $menuPriceCents = null): Category
    {
        $category = new Category();
        $category->setRestaurant($restaurant);
        $category->setActive(true);
        if ($menuPriceCents !== null) {
            $category->setMenuPrice($menuPriceCents);
        }
        $t = new CategoryTranslation();
        $t->setLocale('es');
        $t->setName($name);
        $category->addTranslation($t);
        $restaurant->addCategory($category);

        return $category;
    }

    private function dish(Category $category, string $name, int $priceCents = 1000, ?MenuSection $section = null): Product
    {
        $product = new Product();
        $product->setPosition(0);
        $product->setBasePrice($priceCents);
        $product->setActive(true);
        $product->setCategory($category);
        $category->addProduct($product);
        $section?->addProduct($product);
        $t = new ProductTranslation();
        $t->setLocale('es');
        $t->setName($name);
        $product->addTranslation($t);

        return $product;
    }

    private function tag(Restaurant $restaurant, string $code, string $name, bool $system = true): ProductTag
    {
        $tag = new ProductTag($restaurant, $code, $system);
        $t = new ProductTagTranslation();
        $t->setLocale('es');
        $t->setName($name);
        $tag->addTranslation($t);

        return $tag;
    }

    private function builder(): MenuContextBuilder
    {
        $allergenRepository = $this->createStub(AllergenRepository::class);
        $allergenRepository->method('findAllIndexedByCode')->willReturn($this->allergens);

        return new MenuContextBuilder(
            new ProductAllergenResolver(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(AllergenRepository::class),
                $this->createStub(CacheItemPoolInterface::class),
            ),
            new CurrencyConverter($this->createStub(ExchangeRateRepository::class)),
            new OwnerTextTranslationService(
                $this->createStub(OwnerTextTranslationRepository::class),
                $this->createStub(ProductTranslatorInterface::class),
                new NullLogger(),
                $this->createStub(EntityManagerInterface::class),
            ),
            new SmartWaiterDishSafety(),
            $allergenRepository,
        );
    }

    /**
     * @param ProductTag[] $tags
     * @param array<string, array{0: AllergenPresence, 1?: ?string}> $entries allergen code => [presence, note]
     */
    private function shape(array $tags, array $entries): array
    {
        $allergenEntries = [];
        foreach ($entries as $code => $entry) {
            $allergenEntries[] = ['allergen' => $this->allergens[$code], 'presence' => $entry[0], 'note' => $entry[1] ?? null];
        }

        return (new SmartWaiterDishSafety())->shape(
            $tags,
            $allergenEntries,
            static fn (ProductTag $tag) => $tag->getTranslation('es')->getName(),
            static fn (Allergen $allergen) => $allergen->getTranslation('es')->getName(),
            fn () => $this->allergens,
        );
    }

    // ── 8. a dish without diet labels or allergens says so ──────────────

    public function testADishWithoutLabelsOrAllergensSaysSoExplicitly(): void
    {
        $restaurant = $this->restaurant();
        $this->dish($this->category($restaurant, 'Entrantes'), 'Carpaccio de salmón');

        $dish = $this->builder()->build($restaurant, 'es', 'EUR')['categories'][0]['products'][0];

        self::assertSame('none', $dish['labels']);
        self::assertSame('unknown (not specified by the restaurant)', $dish['allergens']);
        self::assertStringStartsWith('unknown', $dish['allergens'], 'rule 6 reads it by this prefix');
        self::assertArrayNotHasKey('allergen_notes', $dish);
        self::assertArrayNotHasKey('dietary_tags', $dish, 'the old key is gone');
    }

    public function testRecommendedIsHighlightedNotALabel(): void
    {
        $restaurant = $this->restaurant();
        $dish = $this->dish($this->category($restaurant, 'Entrantes'), 'Carpaccio de salmón');
        $dish->addTag($this->tag($restaurant, 'recommended', 'Recomendación del chef'));

        $data = $this->builder()->build($restaurant, 'es', 'EUR')['categories'][0]['products'][0];

        self::assertSame(['recommended'], $data['highlighted']);
        self::assertSame('none', $data['labels']);
    }

    // ── 9. allergens grouped; none = "unknown (…)", never "none …" ───────

    public function testAllergensAreGroupedByPresenceInAllergenOrder(): void
    {
        $shape = $this->shape([], [
            'milk' => [AllergenPresence::CONTAINS],
            'nuts' => [AllergenPresence::MAY_CONTAIN],
            'gluten' => [AllergenPresence::FREE_FROM],
            'eggs' => [AllergenPresence::CONTAINS],
        ]);

        self::assertSame([
            'contains' => ['Huevo', 'Leche'],
            'may_contain' => ['Frutos de cáscara'],
            'free_from' => ['Gluten'],
        ], $shape['allergens']);
    }

    public function testEmptyGroupsAreLeftOutAndNoAllergensReadsNoneRecorded(): void
    {
        self::assertSame(['contains' => ['Leche']], $this->shape([], ['milk' => [AllergenPresence::CONTAINS]])['allergens']);
        self::assertSame('unknown (not specified by the restaurant)', $this->shape([], [])['allergens']);
    }

    // ── 10. a diet label the allergens contradict is left out ──────────

    public function testVeganIsDroppedWhenTheDishContainsAnAnimalAllergen(): void
    {
        $restaurant = $this->restaurant();
        $vegan = $this->tag($restaurant, 'vegan', 'Vegano');

        foreach (['milk', 'eggs', 'fish', 'molluscs'] as $code) {
            self::assertSame('none', $this->shape([$vegan], [$code => [AllergenPresence::CONTAINS]])['labels'], $code);
        }
        // "may contain" traces don't make it non-vegan; other allergens don't either.
        self::assertSame(['Vegano'], $this->shape([$vegan], ['milk' => [AllergenPresence::MAY_CONTAIN]])['labels']);
        self::assertSame(['Vegano'], $this->shape([$vegan], ['gluten' => [AllergenPresence::CONTAINS]])['labels']);
    }

    public function testVegetarianIsDroppedOnlyForFishAndSeafood(): void
    {
        $vegetarian = $this->tag($this->restaurant(), 'vegetarian', 'Vegetariano');

        self::assertSame('none', $this->shape([$vegetarian], ['fish' => [AllergenPresence::CONTAINS]])['labels']);
        self::assertSame('none', $this->shape([$vegetarian], ['molluscs' => [AllergenPresence::CONTAINS]])['labels']);
        self::assertSame(['Vegetariano'], $this->shape([$vegetarian], ['milk' => [AllergenPresence::CONTAINS], 'eggs' => [AllergenPresence::CONTAINS]])['labels']);
    }

    public function testGlutenFreeIsDroppedWhenGlutenIsContainedOrMayBe(): void
    {
        $glutenFree = $this->tag($this->restaurant(), 'gluten-free', 'Sin gluten');

        self::assertSame('none', $this->shape([$glutenFree], ['gluten' => [AllergenPresence::CONTAINS]])['labels']);
        self::assertSame('none', $this->shape([$glutenFree], ['gluten' => [AllergenPresence::MAY_CONTAIN]])['labels']);
        self::assertSame(['Sin gluten'], $this->shape([$glutenFree], ['gluten' => [AllergenPresence::FREE_FROM]])['labels']);
    }

    public function testAnOwnersOwnTagIsKeptAsWrittenAndNeverJudged(): void
    {
        $restaurant = $this->restaurant();
        $ownVegan = $this->tag($restaurant, 'vegano-casa', 'Vegano', system: false);

        self::assertSame(['Vegano'], $this->shape([$ownVegan], ['milk' => [AllergenPresence::CONTAINS]])['labels']);
    }

    /** The real case: vegan + "contains nuts" tags, milk contained — through the whole builder. */
    public function testTheVeganFourCheesePizzaReachesTheModelWithoutTheVeganLabel(): void
    {
        $restaurant = $this->restaurant();
        $pizza = $this->dish($this->category($restaurant, 'Pizzas'), 'Pizza cuatro quesos');
        $pizza->addTag($this->tag($restaurant, 'vegan', 'Vegano'));
        $pizza->addTag($this->tag($restaurant, 'contains-dairy', 'Contiene lácteos'));

        $dish = $this->builder()->build($restaurant, 'es', 'EUR')['categories'][0]['products'][0];

        self::assertSame('none', $dish['labels']);
        self::assertSame(['contains' => ['Leche']], $dish['allergens']);
    }

    // ── 11. a "contains-…" tag is an allergen, and only ever raises it ──

    public function testContainsTagsBecomeContainedAllergens(): void
    {
        $restaurant = $this->restaurant();
        $shape = $this->shape([
            $this->tag($restaurant, 'contains-nuts', 'Contiene frutos secos'),
            $this->tag($restaurant, 'contains-gluten', 'Contiene gluten'),
            $this->tag($restaurant, 'spicy', 'Picante'),
        ], []);

        self::assertSame(['contains' => ['Gluten', 'Frutos de cáscara']], $shape['allergens'], '"contains-nuts" = tree nuts');
        self::assertSame(['Picante'], $shape['labels'], 'the contains- tags are no longer labels');
    }

    public function testAContainsTagRaisesMayContainAndFreeFromButNeverLowersAnything(): void
    {
        $restaurant = $this->restaurant();
        $containsDairy = $this->tag($restaurant, 'contains-dairy', 'Contiene lácteos');
        $containsNuts = $this->tag($restaurant, 'contains-nuts', 'Contiene frutos secos');

        $shape = $this->shape([$containsDairy, $containsNuts], [
            'milk' => [AllergenPresence::FREE_FROM, 'Sin lactosa'],
            'nuts' => [AllergenPresence::MAY_CONTAIN],
            'eggs' => [AllergenPresence::CONTAINS],
        ]);

        self::assertSame(['contains' => ['Huevo', 'Leche', 'Frutos de cáscara']], $shape['allergens']);
    }

    // ── 12. notes travel apart, untouched ───────────────────────────────

    public function testNotesAreKeptApartCharacterForCharacter(): void
    {
        $note = '  Se fríe en el mismo aceite que el "pescado" — ¡ojo!  ';
        $shape = $this->shape([], [
            'gluten' => [AllergenPresence::MAY_CONTAIN, $note],
            'milk' => [AllergenPresence::CONTAINS, ''],
        ]);

        self::assertSame([['allergen' => 'Gluten', 'note' => $note]], $shape['allergen_notes'], 'blank notes are left out');
        self::assertSame(['contains' => ['Leche'], 'may_contain' => ['Gluten']], $shape['allergens']);
    }

    // ── 13. extractVocabulary() reads a fixed-price menu's dishes ───────

    public function testVocabularyReadsSetMenuDishesWithoutFailing(): void
    {
        $restaurant = $this->restaurant();
        $normal = $this->dish($this->category($restaurant, 'Carta'), 'Ensalada');
        $normal->addTag($this->tag($restaurant, 'vegetarian', 'Vegetariano'));

        $menu = $this->category($restaurant, 'Menú del día', 1500);
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $section->setPosition(0);
        $menu->addMenuSection($section);
        $setDish = $this->dish($menu, 'Sopa', 0, $section);
        $setDish->addTag($this->tag($restaurant, 'halal', 'Halal'));
        $setDish->addTag($this->tag($restaurant, 'contains-gluten', 'Contiene gluten'));

        $builder = $this->builder();
        $context = $builder->build($restaurant, 'es', 'EUR');
        self::assertArrayHasKey('sections', $context['categories'][1], 'the fixture really is a set menu');

        $vocabulary = $builder->extractVocabulary($context);

        sort($vocabulary);
        self::assertSame(['Gluten', 'Halal', 'Vegetariano'], $vocabulary);
    }

    public function testTheUnknownAllergensTextNeverEntersTheVocabulary(): void
    {
        $restaurant = $this->restaurant();
        $this->dish($this->category($restaurant, 'Carta'), 'Carpaccio de salmón');
        $menu = $this->category($restaurant, 'Menú del día', 1500);
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $section->setPosition(0);
        $menu->addMenuSection($section);
        $this->dish($menu, 'Sopa', 0, $section);

        $builder = $this->builder();
        $context = $builder->build($restaurant, 'es', 'EUR');
        self::assertSame(SmartWaiterDishSafety::NO_ALLERGENS, $context['categories'][0]['products'][0]['allergens']);
        self::assertSame(SmartWaiterDishSafety::NO_ALLERGENS, $context['categories'][1]['sections'][0]['dishes'][0]['allergens']);

        self::assertSame([], $builder->extractVocabulary($context), 'neither "unknown (…)" nor "none" is a term');
    }
}
