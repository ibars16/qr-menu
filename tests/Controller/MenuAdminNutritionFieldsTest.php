<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for the nutrition fields' admin save path (Fase 2 of 3 — see
 * project memory), exercised through the real HTTP route
 * (POST /admin/products/save, MenuAdminController::saveProduct()), not just
 * the entity layer already covered by ProductNutritionTest (Fase 1).
 *
 * The one behavior worth pinning down precisely: empty must persist as
 * null, but a real "0.0" must survive as "0.0", not collapse to null the
 * way calories' own `?: null` does — that's the whole reason the four new
 * fields are sent/read as raw strings end to end instead of parsed numbers
 * (see saveProduct() and _product_js.html.twig's own comments on this).
 */
final class MenuAdminNutritionFieldsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private Category $category;
    private Product $product;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Nutrition Fields Test Restaurant');
        $this->restaurant->setSlug('nutrition-fields-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->em->persist($this->restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $this->restaurant->getId();

        $this->category = new Category();
        $this->category->setRestaurant($this->restaurant);
        $this->em->persist($this->category);

        $this->product = new Product();
        $this->product->setCategory($this->category);
        $this->product->setBasePrice(1000);
        $this->em->persist($this->product);
        $this->em->flush();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('nutrition-fields-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($this->restaurant);
        $this->em->persist($user);
        $this->em->flush();

        // See MenuAdminMissingPhotoNoticeTest's own note on this: static::getContainer()
        // deliberately survives the client's kernel reboot, so the in-memory
        // $restaurant/$category/$product built above (via `new Entity()`,
        // never re-fetched) would otherwise still be what the next request
        // sees — clearing forces a genuine reload from the database.
        $this->em->clear();

        $this->client->loginUser($user);
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
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function save(array $overrides = []): array
    {
        $payload = array_merge([
            'id' => $this->product->getId(),
            'categoryId' => $this->category->getId(),
            'basePrice' => 10.0,
            'translations' => ['es' => ['name' => 'Test dish', 'description' => null]],
        ], $overrides);

        $this->client->request(
            'POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);

        return ['status' => $this->client->getResponse()->getStatusCode(), 'data' => $data];
    }

    /** Re-reads the product fresh from the database, never the stale in-memory entity. */
    private function reloadProduct(): Product
    {
        $this->em->clear();

        return $this->em->getRepository(Product::class)->find($this->product->getId());
    }

    public function testValidNutritionValuesPersistWithCorrectTypeAndFormat(): void
    {
        $result = $this->save([
            'fat' => '6.5',
            'protein' => '12.3',
            'carbohydrates' => '20.0',
            'sugars' => '3.2',
        ]);

        self::assertSame(200, $result['status']);

        $product = $this->reloadProduct();
        self::assertIsString($product->getFat());
        self::assertSame('6.5', $product->getFat());
        self::assertSame('12.3', $product->getProtein());
        self::assertSame('20.0', $product->getCarbohydrates());
        self::assertSame('3.2', $product->getSugars());
    }

    public function testEmptyNutritionFieldsPersistAsNullNotZero(): void
    {
        // First give the dish real values...
        $this->save(['fat' => '6.5', 'protein' => '12.3', 'carbohydrates' => '20.0', 'sugars' => '3.2']);
        self::assertNotNull($this->reloadProduct()->getFat());

        // ...then blank all four out, exactly like an owner clearing the
        // fields in the form. All four independently nullable — a dish
        // with none filled in must save without any problem.
        $result = $this->save(['fat' => '', 'protein' => '', 'carbohydrates' => '', 'sugars' => '']);
        self::assertSame(200, $result['status']);

        $product = $this->reloadProduct();
        self::assertNull($product->getFat());
        self::assertNull($product->getProtein());
        self::assertNull($product->getCarbohydrates());
        self::assertNull($product->getSugars());
    }

    public function testRealZeroValuePersistsAsZeroNotNull(): void
    {
        // The case that actually proves the string-based approach works:
        // a real 0.0 g of sugar is a legitimate, affirmative value, distinct
        // from "not entered" — it must survive as "0.0", never collapse to
        // null the way calories' own `?: null` would if the same pattern
        // had been copied here.
        $result = $this->save(['sugars' => '0.0']);
        self::assertSame(200, $result['status']);

        $product = $this->reloadProduct();
        self::assertNotNull($product->getSugars(), 'a real 0.0 g must not be persisted as null');
        self::assertSame('0.0', $product->getSugars());
    }

    public function testDishWithAllFourEmptySavesWithoutError(): void
    {
        $result = $this->save(); // no fat/protein/carbohydrates/sugars key at all — the vast majority case

        self::assertSame(200, $result['status']);

        $product = $this->reloadProduct();
        self::assertNull($product->getFat());
        self::assertNull($product->getProtein());
        self::assertNull($product->getCarbohydrates());
        self::assertNull($product->getSugars());
    }

    public static function invalidNutritionValueProvider(): array
    {
        return [
            'non-numeric text' => ['abc'],
            'negative' => ['-5.0'],
            'two decimal digits' => ['6.55'],
            'no leading digit' => ['.5'],
            'above the column range' => ['10000.0'],
        ];
    }

    #[DataProvider('invalidNutritionValueProvider')]
    public function testInvalidNutritionValueIsRejectedWithA400NotA500(string $invalidValue): void
    {
        $result = $this->save(['fat' => $invalidValue]);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('error', $result['data']);

        // Nothing must have been written — the product's fat stays exactly
        // what it was before this rejected request (null, from setUp).
        self::assertNull($this->reloadProduct()->getFat());
    }

    public function testInvalidCaloriesValueIsRejectedWithA400NotA500(): void
    {
        // calories predates this migration and keeps its own `?: null`
        // persistence quirk untouched — this only proves the new format
        // validation in front of it closes the previous TypeError-to-500 gap.
        $result = $this->save(['calories' => 'abc']);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('error', $result['data']);
        self::assertNull($this->reloadProduct()->getCalories());
    }

    public function testValidCaloriesValueIsUnaffectedByTheNewValidation(): void
    {
        $result = $this->save(['calories' => 210]);

        self::assertSame(200, $result['status']);
        self::assertSame(210, $this->reloadProduct()->getCalories());
    }
}
