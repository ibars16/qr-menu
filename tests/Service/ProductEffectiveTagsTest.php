<?php

namespace App\Tests\Service;

use App\Entity\Allergen;
use App\Entity\ProductTag;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Service\ProductEffectiveTags;
use App\Service\SmartWaiterDishSafety;
use PHPUnit\Framework\TestCase;

/**
 * ProductEffectiveTags: saved tags minus the three "contains-…" system
 * tags, plus those three exactly when their allergen is "contains".
 */
final class ProductEffectiveTagsTest extends TestCase
{
    private Restaurant $restaurant;
    /** @var array<string, ProductTag> */
    private array $tags = [];

    protected function setUp(): void
    {
        $this->restaurant = new Restaurant();
        $position = 0;
        foreach (['vegetarian', 'gluten-free', 'contains-dairy', 'contains-nuts', 'contains-gluten', 'recommended'] as $code) {
            $tag = new ProductTag($this->restaurant, $code, true);
            $tag->setPosition($position++);
            $this->restaurant->addProductTag($tag);
            $this->tags[$code] = $tag;
        }
    }

    /** @param string[] $stored @param array<string, string> $presences @return string[] */
    private function codes(array $stored, array $presences, ?iterable $restaurantTags = null): array
    {
        return array_map(
            static fn (ProductTag $t) => $t->getCode(),
            ProductEffectiveTags::compute(
                array_map(fn (string $c) => $this->tags[$c], $stored),
                $presences,
                $restaurantTags ?? $this->restaurant->getProductTags(),
            ),
        );
    }

    public function testEachContainsAllergenAddsItsTag(): void
    {
        self::assertSame(['contains-gluten'], $this->codes([], ['gluten' => 'contains']));
        self::assertSame(['contains-dairy'], $this->codes([], ['milk' => 'contains']));
        self::assertSame(['contains-nuts'], $this->codes([], ['nuts' => 'contains']), 'tree nuts…');
        self::assertSame([], $this->codes([], ['peanuts' => 'contains']), '…not peanuts');
    }

    public function testMayContainAndFreeFromNeverAddIt(): void
    {
        self::assertSame([], $this->codes([], ['gluten' => 'may_contain', 'milk' => 'free_from']));
    }

    public function testATickedContainsTagWithoutTheAllergenIsDropped(): void
    {
        self::assertSame(['vegetarian'], $this->codes(['vegetarian', 'contains-gluten', 'contains-nuts'], []));
        self::assertSame(['vegetarian'], $this->codes(['vegetarian', 'contains-dairy'], ['milk' => 'may_contain']));
    }

    public function testATickedTagWhoseAllergenIsContainedAppearsOnce(): void
    {
        self::assertSame(['contains-gluten'], $this->codes(['contains-gluten'], ['gluten' => 'contains']));
    }

    public function testOtherTagsAreKeptAndEverythingIsInTagOrder(): void
    {
        self::assertSame(
            ['vegetarian', 'gluten-free', 'contains-dairy', 'recommended'],
            $this->codes(['recommended', 'gluten-free', 'vegetarian'], ['milk' => 'contains', 'eggs' => 'contains']),
            'nothing is judged here: "Sin gluten" stays as saved',
        );
    }

    public function testAnOwnersOwnTagIsNeverTreatedAsAnAllergenTag(): void
    {
        $restaurant = new Restaurant();
        $own = new ProductTag($restaurant, 'contains-gluten', false);
        $restaurant->addProductTag($own);

        $tags = ProductEffectiveTags::compute([$own], [], $restaurant->getProductTags());
        self::assertSame([$own], $tags, 'kept as saved');
        self::assertSame([], ProductEffectiveTags::compute([], ['gluten' => 'contains'], $restaurant->getProductTags()), 'never added');
    }

    public function testNothingIsAddedWhenTheRestaurantHasNoSuchSystemTag(): void
    {
        self::assertSame([], $this->codes([], ['gluten' => 'contains'], [$this->tags['vegetarian']]));
    }

    public function testPresencesFromResolverEntries(): void
    {
        $gluten = new Allergen();
        $gluten->setCode('gluten');
        $milk = new Allergen();
        $milk->setCode('milk');

        self::assertSame(['gluten' => 'contains', 'milk' => 'may_contain'], ProductEffectiveTags::presencesFromEntries([
            ['allergen' => $gluten, 'presence' => AllergenPresence::CONTAINS, 'source' => 'computed', 'note' => null],
            ['allergen' => $milk, 'presence' => AllergenPresence::MAY_CONTAIN, 'source' => 'override', 'note' => null],
        ]));
    }

    public function testSmartWaiterReadsTheSameList(): void
    {
        self::assertSame(ProductEffectiveTags::CONTAINS_TAGS, SmartWaiterDishSafety::CONTAINS_TAGS);
    }
}
