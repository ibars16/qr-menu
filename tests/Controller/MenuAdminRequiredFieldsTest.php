<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Server-side guard for the two fields the admin JS's own validateProduct()
 * already requires (name, price) — exercised through the real HTTP route
 * (POST /admin/products/save, MenuAdminController::saveProduct()), same
 * pattern as MenuAdminPriceVariantsTest. Before this guard, a raw API call
 * bypassing the JS could save a product with no name at all (the client
 * only ever sends a translations entry when the name field is non-empty —
 * see saveProduct() in _product_js.html.twig), and an absent `basePrice` on
 * a brand-new Product risked a 500 (Product::$basePrice is a non-nullable,
 * no-default typed int, uninitialized until setBasePrice() runs).
 *
 * Deliberately NOT covered here: rejecting basePrice <= 0 server-side — the
 * product decision was to leave that a client-only rule (see 5894's
 * legitimate "según mercado" €0 price), so the server only rejects
 * ABSENT/EMPTY/NON-NUMERIC, never a real (if unusual) zero.
 */
final class MenuAdminRequiredFieldsTest extends WebTestCase
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
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Required Fields Test Restaurant');
        $this->restaurant->setSlug('required-fields-test-' . uniqid());
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

        $translation = new ProductTranslation();
        $translation->setLocale('es');
        $translation->setName('Plato original');
        $this->product->addTranslation($translation);
        $this->em->persist($translation);
        $this->em->flush();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('required-fields-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($this->restaurant);
        $this->em->persist($user);
        $this->em->flush();

        // See MenuAdminPriceVariantsTest's own note on this: static::getContainer()
        // deliberately survives the client's kernel reboot, so the in-memory
        // $restaurant/$category/$product built above would otherwise still be
        // what the next request sees — clearing forces a genuine reload.
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

    /** Edits $this->product. @param array<string, mixed> $overrides */
    private function saveEdit(array $overrides = []): array
    {
        $payload = array_merge([
            'id'           => $this->product->getId(),
            'categoryId'   => $this->category->getId(),
            'basePrice'    => 12.0,
            'translations' => ['es' => ['name' => 'Plato editado', 'description' => null]],
        ], $overrides);

        return $this->post($payload);
    }

    /** Creates a brand-new product. @param array<string, mixed> $overrides */
    private function saveNew(array $overrides = []): array
    {
        $payload = array_merge([
            'id'           => null,
            'categoryId'   => $this->category->getId(),
            'basePrice'    => 9.5,
            'translations' => ['es' => ['name' => 'Plato nuevo', 'description' => null]],
        ], $overrides);

        return $this->post($payload);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): array
    {
        $this->client->request(
            'POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);

        return ['status' => $this->client->getResponse()->getStatusCode(), 'data' => $data];
    }

    /** Re-reads $this->product fresh from the database, never the stale in-memory entity. */
    private function reloadProduct(): Product
    {
        $this->em->clear();

        return $this->em->getRepository(Product::class)->find($this->product->getId());
    }

    private function productCountInCategory(): int
    {
        $this->em->clear();

        return count($this->em->getRepository(Product::class)->findBy(['category' => $this->category]));
    }

    // ── Name ─────────────────────────────────────────────────────────────

    public function testEditingWithABlankNameIsRejected(): void
    {
        $result = $this->saveEdit(['translations' => ['es' => ['name' => '   ', 'description' => null]]]);

        self::assertSame(400, $result['status']);
        self::assertSame('El nombre es obligatorio', $result['data']['error']);
        self::assertSame('Plato original', $this->reloadProduct()->getTranslation('es')->getName());
    }

    public function testEditingWithNoTranslationsAtAllIsRejected(): void
    {
        $payload = [
            'id'         => $this->product->getId(),
            'categoryId' => $this->category->getId(),
            'basePrice'  => 12.0,
            // No 'translations' key at all — the shape a raw API call
            // bypassing the admin JS could send.
        ];
        $result = $this->post($payload);

        self::assertSame(400, $result['status']);
        self::assertSame('El nombre es obligatorio', $result['data']['error']);
        self::assertSame('Plato original', $this->reloadProduct()->getTranslation('es')->getName());
    }

    public function testCreatingWithoutAnyTranslationIsRejected(): void
    {
        $before = $this->productCountInCategory();
        $result = $this->saveNew(['translations' => []]);

        self::assertSame(400, $result['status']);
        self::assertSame('El nombre es obligatorio', $result['data']['error']);
        self::assertSame($before, $this->productCountInCategory(), 'no row must be created');
    }

    // ── Price ────────────────────────────────────────────────────────────

    public function testCreatingWithoutBasePriceIsRejectedNot500(): void
    {
        $before  = $this->productCountInCategory();
        $payload = [
            'id'           => null,
            'categoryId'   => $this->category->getId(),
            'translations' => ['es' => ['name' => 'Plato nuevo', 'description' => null]],
            // No 'basePrice' key at all — the exact shape that would leave
            // Product::$basePrice (non-nullable, no default) uninitialized
            // all the way to flush() for a brand-new Product.
        ];
        $result = $this->post($payload);

        self::assertSame(400, $result['status']);
        self::assertSame('El precio es obligatorio', $result['data']['error']);
        self::assertSame($before, $this->productCountInCategory(), 'no row must be created, and no uncaught Error either');
    }

    public function testCreatingWithEmptyBasePriceIsRejected(): void
    {
        $before = $this->productCountInCategory();
        $result = $this->saveNew(['basePrice' => '']);

        self::assertSame(400, $result['status']);
        self::assertSame('El precio es obligatorio', $result['data']['error']);
        self::assertSame($before, $this->productCountInCategory());
    }

    public function testCreatingWithNonNumericBasePriceIsRejected(): void
    {
        $before = $this->productCountInCategory();
        $result = $this->saveNew(['basePrice' => 'abc']);

        self::assertSame(400, $result['status']);
        self::assertSame('El precio es obligatorio', $result['data']['error']);
        self::assertSame($before, $this->productCountInCategory());
    }

    public function testEditingWithoutBasePriceIsRejected(): void
    {
        $result = $this->saveEdit(['basePrice' => null]);

        self::assertSame(400, $result['status']);
        self::assertSame('El precio es obligatorio', $result['data']['error']);
        self::assertSame(1000, $this->reloadProduct()->getBasePrice(), 'unchanged, not silently zeroed');
    }

    // ── Price 0 is NOT a server-side rejection — 5894's own "según mercado" case ──

    public function testZeroIsAcceptedServerSide(): void
    {
        // The client still refuses to submit this (validateProduct() rejects
        // <=0) — this is only pinning down that the server itself does not
        // duplicate that rule, per the product decision to keep it client-only.
        $result = $this->saveEdit(['basePrice' => 0]);

        self::assertSame(200, $result['status']);
        self::assertSame(0, $this->reloadProduct()->getBasePrice());
    }

    // ── Happy path — must still work ─────────────────────────────────────

    public function testValidEditStillSucceeds(): void
    {
        $result = $this->saveEdit();

        self::assertSame(200, $result['status']);
        $product = $this->reloadProduct();
        self::assertSame('Plato editado', $product->getTranslation('es')->getName());
        self::assertSame(1200, $product->getBasePrice());
    }

    public function testValidCreateStillSucceeds(): void
    {
        $before = $this->productCountInCategory();
        $result = $this->saveNew();

        self::assertSame(200, $result['status']);
        self::assertSame($before + 1, $this->productCountInCategory());
    }
}
