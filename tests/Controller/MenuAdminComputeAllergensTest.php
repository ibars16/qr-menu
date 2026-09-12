<?php

namespace App\Tests\Controller;

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
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\AllergenPresence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for POST /admin/products/compute-allergens
 * (MenuAdminController::computeAllergens()) — the live-recompute endpoint
 * behind the "Según tus ingredientes" refresh-on-add/remove fix. Exercises
 * the real HTTP route, not just ProductAllergenResolver directly (that's
 * ProductAllergenResolverTest's job): this is where the endpoint-specific
 * concerns live — tenant scoping of raw ingredient ids, the optional
 * product id, and new: contributing nothing.
 */
final class MenuAdminComputeAllergensTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private Restaurant $restaurant;
    private Category $category;
    private Product $product;
    private Ingredient $glutenIngredient;
    private GlobalIngredient $globalMilk;

    private Restaurant $otherRestaurant;
    private Ingredient $otherRestaurantIngredient;
    private Product $otherRestaurantProduct;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    /** @var int[] */
    private array $globalIngredientIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = $this->makeRestaurant('compute-allergens-test');
        $this->category = new Category();
        $this->category->setRestaurant($this->restaurant);
        $this->em->persist($this->category);

        $this->product = new Product();
        $this->product->setCategory($this->category);
        $this->product->setBasePrice(1000);
        $this->em->persist($this->product);

        $this->glutenIngredient = $this->makeIngredient($this->restaurant, 'gluten');

        $this->globalMilk = new GlobalIngredient();
        $this->globalMilk->setCode('test-global-milk-' . uniqid());
        $translation = new GlobalIngredientTranslation();
        $translation->setLocale('es');
        $translation->setName('Leche de test');
        $this->globalMilk->addTranslation($translation);
        $link = new GlobalIngredientAllergen();
        $link->setAllergen($this->allergen('milk'));
        $link->setPresence(AllergenPresence::CONTAINS);
        $this->globalMilk->addAllergenLink($link);
        $this->em->persist($this->globalMilk);
        $this->em->persist($translation);
        $this->em->persist($link);
        $this->em->flush();
        $this->globalIngredientIdsToRemove[] = $this->globalMilk->getId();

        // A second, unrelated restaurant — its own private ingredient must
        // never be readable through this endpoint by another restaurant's
        // owner, and its own product must 404 rather than leak anything.
        $this->otherRestaurant = $this->makeRestaurant('compute-allergens-other');
        $otherCategory = new Category();
        $otherCategory->setRestaurant($this->otherRestaurant);
        $this->em->persist($otherCategory);
        $this->otherRestaurantProduct = new Product();
        $this->otherRestaurantProduct->setCategory($otherCategory);
        $this->otherRestaurantProduct->setBasePrice(500);
        $this->em->persist($this->otherRestaurantProduct);
        $this->otherRestaurantIngredient = $this->makeIngredient($this->otherRestaurant, 'eggs');

        $this->em->flush();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('compute-allergens-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($this->restaurant);
        $this->em->persist($user);
        $this->em->flush();

        // Same reasoning as MenuAdminNutritionFieldsTest: force a genuine
        // reload from the database for the next request.
        $this->em->clear();

        $this->restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurant->getId());
        $this->product = $this->em->getRepository(Product::class)->find($this->product->getId());
        $this->glutenIngredient = $this->em->getRepository(Ingredient::class)->find($this->glutenIngredient->getId());
        $this->globalMilk = $this->em->getRepository(GlobalIngredient::class)->find($this->globalMilk->getId());
        $this->otherRestaurant = $this->em->getRepository(Restaurant::class)->find($this->otherRestaurant->getId());
        $this->otherRestaurantProduct = $this->em->getRepository(Product::class)->find($this->otherRestaurantProduct->getId());
        $this->otherRestaurantIngredient = $this->em->getRepository(Ingredient::class)->find($this->otherRestaurantIngredient->getId());

        $this->client->loginUser($this->em->getRepository(User::class)->find($user->getId()));
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
                $this->em->remove($ingredient);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
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

    private function makeRestaurant(string $slugPrefix): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Compute Allergens Test Restaurant');
        $restaurant->setSlug($slugPrefix . '-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $restaurant;
    }

    private function makeIngredient(Restaurant $restaurant, string $allergenCode): Ingredient
    {
        $ingredient = new Ingredient();
        $ingredient->setCode('test-' . $allergenCode . '-' . uniqid());
        $ingredient->setRestaurant($restaurant);
        $translation = new IngredientTranslation();
        $translation->setLocale('es');
        $translation->setName('Ingrediente de test (' . $allergenCode . ')');
        $ingredient->addTranslation($translation);
        $link = new IngredientAllergen();
        $link->setAllergen($this->allergen($allergenCode));
        $link->setPresence(AllergenPresence::CONTAINS);
        $ingredient->addAllergenLink($link);
        $this->em->persist($ingredient);
        $this->em->persist($translation);
        $this->em->persist($link);

        return $ingredient;
    }

    private function allergen(string $code): Allergen
    {
        return $this->em->getRepository(Allergen::class)->findOneBy(['code' => $code]);
    }

    /** @param array<string, mixed> $payload */
    private function compute(array $payload): array
    {
        $this->client->request(
            'POST', '/admin/products/compute-allergens', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'data' => json_decode($this->client->getResponse()->getContent(), true),
        ];
    }

    private function codes(array $data): array
    {
        return array_column($data['allergensComputed'] ?? [], 'code');
    }

    public function testComputesAllergensForCurrentSelection(): void
    {
        $result = $this->compute([
            'id' => $this->product->getId(),
            'ingredients' => [['value' => 'r:' . $this->glutenIngredient->getId(), 'name' => 'x']],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertContains('gluten', $this->codes($result['data']));
    }

    public function testRemovingTheIngredientClearsItsAllergen(): void
    {
        $result = $this->compute([
            'id' => $this->product->getId(),
            'ingredients' => [],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['allergensComputed']);
    }

    public function testNewUnsavedIngredientContributesNoAllergens(): void
    {
        $result = $this->compute([
            'id' => $this->product->getId(),
            'ingredients' => [['value' => 'new:Leche', 'name' => 'Leche']],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['allergensComputed']);
    }

    public function testWorksForABrandNewProductWithNoIdYet(): void
    {
        $result = $this->compute([
            'id' => null,
            'ingredients' => [['value' => 'g:' . $this->globalMilk->getId(), 'name' => 'x']],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertContains('milk', $this->codes($result['data']));
    }

    public function testSavedOverrideSuppressesTheComputedAllergen(): void
    {
        $product = $this->em->getRepository(Product::class)->find($this->product->getId());
        $override = new ProductAllergenOverride();
        $override->setAllergen($this->allergen('gluten'));
        $override->setPresence(AllergenPresence::FREE_FROM);
        $override->setNote('Certified gluten-free substitute (test)');
        $product->addAllergenOverride($override);
        $this->em->persist($override);
        $this->em->flush();
        $this->em->clear();

        $result = $this->compute([
            'id' => $this->product->getId(),
            'ingredients' => [['value' => 'r:' . $this->glutenIngredient->getId(), 'name' => 'x']],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertNotContains('gluten', $this->codes($result['data']));
    }

    public function testAnotherRestaurantsIngredientIdIsSilentlyIgnored(): void
    {
        $result = $this->compute([
            'id' => $this->product->getId(),
            'ingredients' => [['value' => 'r:' . $this->otherRestaurantIngredient->getId(), 'name' => 'x']],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['allergensComputed']);
    }

    public function testAnotherRestaurantsProductIdReturns404(): void
    {
        $result = $this->compute([
            'id' => $this->otherRestaurantProduct->getId(),
            'ingredients' => [],
        ]);

        $this->assertSame(404, $result['status']);
    }
}
