<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Server-side guard for the price-variants ("varios precios") block,
 * exercised through the real HTTP route (POST /admin/products/save,
 * MenuAdminController::saveProduct()) — same pattern as
 * MenuAdminNutritionFieldsTest.
 *
 * The admin JS (validateProduct()/removePriceVariantRow() in
 * _product_js.html.twig) already stops an owner from ever submitting an
 * incomplete row or fewer than 2 total prices from the form itself; this
 * pins down the server's own guard against a raw API call bypassing that —
 * a dish must never end up with a label but no second price, or a variant
 * missing its own label/price.
 */
final class MenuAdminPriceVariantsTest extends WebTestCase
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
        $this->restaurant->setName('Price Variants Test Restaurant');
        $this->restaurant->setSlug('price-variants-test-' . uniqid());
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

        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('price-variants-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($this->restaurant);
        $this->em->persist($user);
        $this->em->flush();

        // See MenuAdminNutritionFieldsTest's own note on this: static::getContainer()
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

    public function testOrdinarySinglePriceSaveIsUnaffected(): void
    {
        // No basePriceLabel/priceVariants key at all — the vast majority
        // case, must keep working exactly as before this guard existed.
        $result = $this->save();

        self::assertSame(200, $result['status']);

        $product = $this->reloadProduct();
        self::assertNull($product->getBasePriceLabel());
        self::assertCount(0, $product->getPriceVariants());
    }

    public function testTwoValidPricesSaveSuccessfully(): void
    {
        $result = $this->save([
            'basePriceLabel' => 'Media ración',
            'priceVariants' => [['label' => 'Ración', 'price' => 8.0]],
        ]);

        self::assertSame(200, $result['status']);

        $product = $this->reloadProduct();
        self::assertSame('Media ración', $product->getBasePriceLabel());
        self::assertCount(1, $product->getPriceVariants());
        /** @var ProductPriceVariant $variant */
        $variant = $product->getPriceVariants()->first();
        self::assertSame('Ración', $variant->getLabel());
        self::assertSame(800, $variant->getPrice());
    }

    public function testBaseLabelWithoutAnyVariantIsRejected(): void
    {
        // Only 1 price total (the labeled base) — below the 2-price minimum.
        $result = $this->save(['basePriceLabel' => 'Media ración']);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('error', $result['data']);
        self::assertNull($this->reloadProduct()->getBasePriceLabel());
    }

    public function testVariantWithoutBaseLabelIsRejected(): void
    {
        // A variant row exists but the base row was never given its own
        // label — same "only 1 real price so far" defect from the other side.
        $result = $this->save([
            'priceVariants' => [['label' => 'Ración', 'price' => 8.0]],
        ]);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('error', $result['data']);
        self::assertCount(0, $this->reloadProduct()->getPriceVariants());
    }

    public function testVariantWithEmptyLabelIsRejected(): void
    {
        $result = $this->save([
            'basePriceLabel' => 'Media ración',
            'priceVariants' => [['label' => '  ', 'price' => 8.0]],
        ]);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('error', $result['data']);
        self::assertNull($this->reloadProduct()->getBasePriceLabel());
        self::assertCount(0, $this->reloadProduct()->getPriceVariants());
    }

    public function testVariantWithNonNumericPriceIsRejected(): void
    {
        $result = $this->save([
            'basePriceLabel' => 'Media ración',
            'priceVariants' => [['label' => 'Ración', 'price' => 'abc']],
        ]);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('error', $result['data']);
        self::assertNull($this->reloadProduct()->getBasePriceLabel());
    }

    public function testCollapsingBackToSinglePriceIsAllowed(): void
    {
        // First save a valid 2-price dish...
        $this->save([
            'basePriceLabel' => 'Media ración',
            'priceVariants' => [['label' => 'Ración', 'price' => 8.0]],
        ]);
        self::assertCount(1, $this->reloadProduct()->getPriceVariants());

        // ...then collapse back to a single price, exactly like clicking
        // "Volver a precio único" and saving — must never be blocked by the
        // same minimum-2 rule that protects against the opposite mistake.
        $result = $this->save(['basePriceLabel' => null, 'priceVariants' => []]);

        self::assertSame(200, $result['status']);
        $product = $this->reloadProduct();
        self::assertNull($product->getBasePriceLabel());
        self::assertCount(0, $product->getPriceVariants());
    }

    public function testFullFourPriceRowSaveSucceeds(): void
    {
        $result = $this->save([
            'basePriceLabel' => 'Tapa',
            'priceVariants' => [
                ['label' => 'Media ración', 'price' => 6.5],
                ['label' => 'Ración', 'price' => 9.0],
                ['label' => 'Fuente', 'price' => 15.0],
            ],
        ]);

        self::assertSame(200, $result['status']);
        self::assertCount(3, $this->reloadProduct()->getPriceVariants());
    }
}
