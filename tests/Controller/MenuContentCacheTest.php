<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The one thing worth pinning down about the public menu's content cache
 * (see MenuController's menuCacheKeyPrefix()/shouldBypassMenuCache() and
 * Restaurant::$menuContentVersion): an admin edit must be visible on the
 * very next public menu load, not just "eventually" once the 6h safety-net
 * TTL expires. This is a regression test for the invalidation, not a test
 * that caching exists at all.
 */
final class MenuContentCacheTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private Category $category;
    private Product $product;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Cache Test Restaurant');
        $this->restaurant->setSlug('cache-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->em->persist($this->restaurant);

        $this->category = new Category();
        $this->category->setActive(true);
        // addCategory() (not setRestaurant() alone) — maintains both sides
        // of the relation on these freshly-constructed, never-reloaded
        // objects; the test client shares this same EntityManager/request,
        // so Restaurant::$categories (an ArrayCollection since construction,
        // never converted to a lazy PersistentCollection) would otherwise
        // stay empty when MenuController reads $restaurant->getCategories().
        $this->restaurant->addCategory($this->category);
        $this->em->persist($this->category);

        $categoryTranslation = new CategoryTranslation();
        $categoryTranslation->setLocale('es');
        $categoryTranslation->setName('Categoría de prueba');
        $this->category->addTranslation($categoryTranslation); // same both-sides reasoning as addCategory()/addProduct() above
        $this->em->persist($categoryTranslation);

        $this->product = new Product();
        $this->product->setBasePrice(1000);
        $this->product->setActive(true);
        $this->category->addProduct($this->product); // see the addCategory() comment above — same reasoning
        $this->em->persist($this->product);

        $originalTranslation = new ProductTranslation();
        $originalTranslation->setLocale('es');
        $originalTranslation->setName('Nombre original');
        $this->product->addTranslation($originalTranslation); // same both-sides reasoning as addCategory()/addProduct() above
        $this->em->persist($originalTranslation);

        $this->owner = new User();
        $this->owner->setEmail('cache-test-' . uniqid() . '@example.test');
        $this->owner->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->owner, 'irrelevant-password-1'));
        $this->owner->setRoles([User::ROLE_OWNER]);
        $this->owner->setRestaurant($this->restaurant);
        $this->em->persist($this->owner);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurant->getId());
        if ($restaurant) {
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
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testEditingAProductIsVisibleOnTheVeryNextMenuLoad(): void
    {
        $slug = $this->restaurant->getSlug();

        // First load populates the content cache.
        $this->client->request('GET', "/r/{$slug}?lang=es");
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nombre original', $this->client->getResponse()->getContent());

        // A second, unchanged load must still show the same (cached) name —
        // sanity check that the cache is actually being read, not just
        // written and ignored.
        $this->client->request('GET', "/r/{$slug}?lang=es");
        self::assertStringContainsString('Nombre original', $this->client->getResponse()->getContent());

        // Edit the dish's name as the owner.
        $this->em->clear();
        $owner = $this->em->getRepository(User::class)->find($this->owner->getId());
        $product = $this->em->getRepository(Product::class)->find($this->product->getId());
        $this->client->loginUser($owner);
        $this->client->request(
            'POST',
            "/admin/products/{$product->getId()}/translations/es",
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Nombre actualizado', 'description' => ''])
        );
        self::assertResponseIsSuccessful();

        // The very next public load must show the new name, not the cached one.
        $this->client->request('GET', "/r/{$slug}?lang=es");
        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Nombre actualizado', $content);
        self::assertStringNotContainsString('Nombre original', $content);
    }
}
