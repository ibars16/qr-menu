<?php

namespace App\Tests\Service;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientAllergen;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientAllergen;
use App\Entity\IngredientTranslation;
use App\Entity\Product;
use App\Entity\ProductAllergenOverride;
use App\Entity\ProductGlobalIngredient;
use App\Entity\ProductIngredient;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Service\ProductAllergenResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the resolveForProducts()/resolveForIngredients() refactor (see
 * buildEntries() — the union + "override always wins" algorithm now lives
 * in exactly one place, shared by both). resolveForProducts() is what the
 * public menu (MenuController/MenuContextBuilder) reads, so this is the
 * regression test that matters most in this file: if the refactor ever
 * makes the two entry points diverge, the admin editor's live preview could
 * show something the public menu wouldn't — which is the one outcome this
 * whole design was meant to prevent.
 */
final class ProductAllergenResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProductAllergenResolver $resolver;

    private Restaurant $restaurant;
    private Ingredient $privateGluten;
    private Ingredient $privateMilkContains;
    private GlobalIngredient $globalMilkMayContain;
    private Product $product;

    /** @var int[] */
    private array $globalIngredientIdsToRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(ProductAllergenResolver::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Allergen Resolver Test Restaurant');
        $this->restaurant->setSlug('allergen-resolver-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->em->persist($this->restaurant);

        $category = new Category();
        $category->setRestaurant($this->restaurant);
        $this->em->persist($category);

        // Private ingredient #1 — carries gluten (contains). Will be
        // suppressed from the computed bucket by a product-level override.
        $this->privateGluten = new Ingredient();
        $this->privateGluten->setCode('test-flour-' . uniqid());
        $this->privateGluten->setRestaurant($this->restaurant);
        $glutenTranslation = new IngredientTranslation();
        $glutenTranslation->setLocale('es');
        $glutenTranslation->setName('Harina de test');
        $this->privateGluten->addTranslation($glutenTranslation);
        $glutenLink = new IngredientAllergen();
        $glutenLink->setAllergen($this->allergen('gluten'));
        $glutenLink->setPresence(AllergenPresence::CONTAINS);
        $this->privateGluten->addAllergenLink($glutenLink);
        $this->em->persist($this->privateGluten);
        $this->em->persist($glutenTranslation);
        $this->em->persist($glutenLink);

        // Private ingredient #2 — carries milk as CONTAINS.
        $this->privateMilkContains = new Ingredient();
        $this->privateMilkContains->setCode('test-cream-' . uniqid());
        $this->privateMilkContains->setRestaurant($this->restaurant);
        $milkTranslation = new IngredientTranslation();
        $milkTranslation->setLocale('es');
        $milkTranslation->setName('Nata de test');
        $this->privateMilkContains->addTranslation($milkTranslation);
        $milkContainsLink = new IngredientAllergen();
        $milkContainsLink->setAllergen($this->allergen('milk'));
        $milkContainsLink->setPresence(AllergenPresence::CONTAINS);
        $this->privateMilkContains->addAllergenLink($milkContainsLink);
        $this->em->persist($this->privateMilkContains);
        $this->em->persist($milkTranslation);
        $this->em->persist($milkContainsLink);

        // Global ingredient — carries milk as MAY_CONTAIN. Combined with the
        // private ingredient above (CONTAINS), the union must upgrade to
        // CONTAINS — never downgrade the other way.
        $this->globalMilkMayContain = new GlobalIngredient();
        $this->globalMilkMayContain->setCode('test-global-milk-trace-' . uniqid());
        $globalMilkTranslation = new GlobalIngredientTranslation();
        $globalMilkTranslation->setLocale('es');
        $globalMilkTranslation->setName('Leche en polvo de test');
        $this->globalMilkMayContain->addTranslation($globalMilkTranslation);
        $globalMilkLink = new GlobalIngredientAllergen();
        $globalMilkLink->setAllergen($this->allergen('milk'));
        $globalMilkLink->setPresence(AllergenPresence::MAY_CONTAIN);
        $this->globalMilkMayContain->addAllergenLink($globalMilkLink);
        $this->em->persist($this->globalMilkMayContain);
        $this->em->persist($globalMilkTranslation);
        $this->em->persist($globalMilkLink);
        $this->em->flush();
        $this->globalIngredientIdsToRemove[] = $this->globalMilkMayContain->getId();

        $this->product = new Product();
        $this->product->setCategory($category);
        $this->product->setBasePrice(1000);
        $this->em->persist($this->product);
        $this->em->flush();

        $ingLink = new ProductIngredient();
        $ingLink->setIngredient($this->privateGluten);
        $ingLink->setPosition(0);
        $this->product->addIngredientLink($ingLink);
        $this->em->persist($ingLink);

        $ingLink2 = new ProductIngredient();
        $ingLink2->setIngredient($this->privateMilkContains);
        $ingLink2->setPosition(1);
        $this->product->addIngredientLink($ingLink2);
        $this->em->persist($ingLink2);

        $globalLink = new ProductGlobalIngredient();
        $globalLink->setGlobalIngredient($this->globalMilkMayContain);
        $globalLink->setPosition(2);
        $this->product->addGlobalIngredientLink($globalLink);
        $this->em->persist($globalLink);

        // Override: gluten -> FREE_FROM (suppresses the computed gluten
        // entry) and eggs -> FREE_FROM (an allergen no ingredient carries at
        // all — must still surface, override-only).
        $glutenOverride = new ProductAllergenOverride();
        $glutenOverride->setAllergen($this->allergen('gluten'));
        $glutenOverride->setPresence(AllergenPresence::FREE_FROM);
        $glutenOverride->setNote('Certified gluten-free substitute (test)');
        $this->product->addAllergenOverride($glutenOverride);
        $this->em->persist($glutenOverride);

        $eggsOverride = new ProductAllergenOverride();
        $eggsOverride->setAllergen($this->allergen('eggs'));
        $eggsOverride->setPresence(AllergenPresence::FREE_FROM);
        $eggsOverride->setNote('Egg-free kitchen for this dish (test)');
        $this->product->addAllergenOverride($eggsOverride);
        $this->em->persist($eggsOverride);

        $this->em->flush();
        $this->em->clear();

        $this->restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurant->getId());
        $this->product = $this->em->getRepository(Product::class)->find($this->product->getId());
        $this->privateGluten = $this->em->getRepository(Ingredient::class)->find($this->privateGluten->getId());
        $this->privateMilkContains = $this->em->getRepository(Ingredient::class)->find($this->privateMilkContains->getId());
        $this->globalMilkMayContain = $this->em->getRepository(GlobalIngredient::class)->find($this->globalMilkMayContain->getId());
    }

    protected function tearDown(): void
    {
        $product = $this->em->getRepository(Product::class)->find($this->product->getId());
        if ($product) {
            $this->em->remove($product);
        }
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurant->getId());
        if ($restaurant) {
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                $this->em->remove($category);
            }
            foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
                $this->em->remove($ingredient);
            }
            $this->em->remove($restaurant);
        }
        foreach ($this->globalIngredientIdsToRemove as $id) {
            $globalIngredient = $this->em->getRepository(GlobalIngredient::class)->find($id);
            if ($globalIngredient) {
                $this->em->remove($globalIngredient);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function allergen(string $code): Allergen
    {
        return $this->em->getRepository(Allergen::class)->findOneBy(['code' => $code]);
    }

    /** @param list<array{allergen: Allergen, presence: AllergenPresence, source: string, note: ?string}> $entries */
    private function summarize(array $entries): array
    {
        return array_map(
            static fn (array $e) => [$e['allergen']->getCode(), $e['presence']->value, $e['source']],
            $entries
        );
    }

    public function testResolveForProductsUnionsUpgradesAndAppliesOverrides(): void
    {
        $entries = $this->resolver->resolveForProduct($this->product);
        $summary = $this->summarize($entries);

        // milk: private CONTAINS + global MAY_CONTAIN -> upgraded to CONTAINS, computed.
        $this->assertContains(['milk', 'contains', 'computed'], $summary);
        // gluten: computed CONTAINS exists in the join tables, but is overridden -> never appears as computed.
        $this->assertNotContains(['gluten', 'contains', 'computed'], $summary);
        // gluten override wins, shown as override source.
        $this->assertContains(['gluten', 'free_from', 'override'], $summary);
        // eggs: no ingredient carries it, override-only entry still surfaces.
        $this->assertContains(['eggs', 'free_from', 'override'], $summary);

        // Sorted by Allergen::$position, whatever the seeded catalog order is.
        $codes = array_column($summary, 0);
        $sorted = $codes;
        usort($sorted, fn ($a, $b) => $this->allergen($a)->getPosition() <=> $this->allergen($b)->getPosition());
        $this->assertSame($sorted, $codes);
    }

    /**
     * The actual regression guard: resolveForIngredients() (raw ingredient
     * ids, used by the admin editor's live preview) must produce EXACTLY
     * the same entries as resolveForProducts() (product id, used by the
     * public menu) for the equivalent underlying ingredient set — because
     * they share buildEntries(), not because someone kept two
     * implementations in sync by hand.
     */
    public function testResolveForIngredientsMatchesResolveForProductsForEquivalentData(): void
    {
        $viaProduct = $this->summarize($this->resolver->resolveForProduct($this->product));

        $viaIngredients = $this->summarize($this->resolver->resolveForIngredients(
            [$this->privateGluten->getId(), $this->privateMilkContains->getId()],
            [$this->globalMilkMayContain->getId()],
            $this->product->getId(),
        ));

        $this->assertSame($viaProduct, $viaIngredients);
    }

    public function testResolveForIngredientsWithoutProductIdAppliesNoOverrides(): void
    {
        // Same ingredients, but no product id — as for a brand-new,
        // not-yet-saved product: gluten must come back as computed
        // (nothing to suppress it), not overridden, and eggs must not
        // appear at all (no override, no ingredient carries it).
        $summary = $this->summarize($this->resolver->resolveForIngredients(
            [$this->privateGluten->getId(), $this->privateMilkContains->getId()],
            [$this->globalMilkMayContain->getId()],
            null,
        ));

        $this->assertContains(['gluten', 'contains', 'computed'], $summary);
        $this->assertContains(['milk', 'contains', 'computed'], $summary);
        $this->assertNotContains(['eggs', 'free_from', 'override'], $summary);
    }

    public function testResolveForIngredientsWithNoIngredientsReturnsEmpty(): void
    {
        $this->assertSame([], $this->resolver->resolveForIngredients([], [], null));
    }
}
