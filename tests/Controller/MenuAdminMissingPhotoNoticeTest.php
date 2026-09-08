<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for the "N platos sin foto" notice on the Carta screen
 * (MenuAdminController::menu(), admin/menu.html.twig's #missing-photo-banner)
 * — added 2026-09-08.
 *
 * Verified via real HTTP requests through the actual admin route (not a
 * direct repository/service call), same as SettingsControllerCrossTenantLogoTest:
 * two distinct restaurants, each with its own owner and its own dishes
 * (some with a photo, some without), asserting the banner on GET /admin/menu
 * only ever reflects the logged-in owner's own restaurant. The count itself
 * is computed in the controller from $restaurant->getCategories() (the same
 * object graph $totalProducts already uses) rather than a separate query, so
 * there is no WHERE clause here that could leak — this test is the proof
 * that holds regardless.
 *
 * No GD, no real image bytes needed: Product::$image is just a filename
 * string and the banner only cares whether it's null, so a fixture like
 * SetMenuRenderingTest's `setImage('con-foto.jpg')` (an arbitrary string,
 * no file on disk) is sufficient — no upload endpoint is exercised here.
 */
final class MenuAdminMissingPhotoNoticeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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

    /** @param int $withoutPhoto how many dishes to create with no image; $withPhoto how many with one (proves those are excluded from the count) */
    private function makeRestaurantWithDishes(string $name, int $withoutPhoto, int $withPhoto): User
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setSlug('missing-photo-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        $category = new Category();
        $category->setRestaurant($restaurant);
        $this->em->persist($category);

        for ($i = 0; $i < $withoutPhoto; $i++) {
            $product = new Product();
            $product->setCategory($category);
            $product->setBasePrice(1000);
            $this->em->persist($product);
        }
        for ($i = 0; $i < $withPhoto; $i++) {
            $product = new Product();
            $product->setCategory($category);
            $product->setBasePrice(1000);
            $product->setImage('con-foto-' . $i . '.jpg');
            $this->em->persist($product);
        }
        $this->em->flush();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('missing-photo-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        // $restaurant was built here via `new Restaurant()`, so its
        // `categories` collection is a plain, permanently-empty
        // ArrayCollection — the products/categories above were only ever
        // wired from their own owning side (setCategory/setRestaurant), so
        // that inverse collection was never told about them. static::getContainer()
        // intentionally survives the client's kernel reboot between requests
        // (that's the whole point of the test container), so the *same*
        // EntityManager — and the *same* stale $restaurant PHP object — is
        // still what the controller sees on the next request unless the
        // identity map is cleared here, forcing a genuine reload.
        $this->em->clear();

        return $user;
    }

    private function bannerText(): string
    {
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();

        $banner = $crawler->filter('#missing-photo-banner');
        self::assertCount(1, $banner, 'expected exactly one #missing-photo-banner in the response');

        return trim(preg_replace('/\s+/', ' ', $banner->text()));
    }

    public function testBannerCountNeverCrossesTenants(): void
    {
        // Restaurant A: 3 dishes without a photo, 2 with. Restaurant B: 1
        // without, 4 with. Deliberately different from A's so a leak in
        // either direction (A seeing B's count, or the two summed together)
        // is unambiguous.
        $ownerA = $this->makeRestaurantWithDishes('Missing Photo A', withoutPhoto: 3, withPhoto: 2);
        $ownerB = $this->makeRestaurantWithDishes('Missing Photo B', withoutPhoto: 1, withPhoto: 4);

        $this->client->loginUser($ownerA);
        self::assertStringContainsString('3', $this->bannerText(), "restaurant A's banner must show its own count (3), not B's or a sum");

        $this->client->loginUser($ownerB);
        self::assertStringContainsString('1', $this->bannerText(), "restaurant B's banner must show its own count (1), not A's or a sum");
        self::assertStringNotContainsString('4', $this->bannerText(), "restaurant B's banner must not mention its with-photo count");
    }

    public function testSingularWordingForExactlyOneMissingPhoto(): void
    {
        $owner = $this->makeRestaurantWithDishes('Missing Photo Singular', withoutPhoto: 1, withPhoto: 0);
        $this->client->loginUser($owner);

        $text = $this->bannerText();
        self::assertStringContainsString('1 plato', $text);
        self::assertStringNotContainsString('1 platos', $text, 'singular count must not pluralize the noun');
    }

    public function testPluralWordingForMultipleMissingPhotos(): void
    {
        $owner = $this->makeRestaurantWithDishes('Missing Photo Plural', withoutPhoto: 5, withPhoto: 0);
        $this->client->loginUser($owner);

        self::assertStringContainsString('5 platos', $this->bannerText());
    }

    public function testBannerAbsentWhenNothingIsMissingAPhoto(): void
    {
        $owner = $this->makeRestaurantWithDishes('Missing Photo None', withoutPhoto: 0, withPhoto: 3);
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#missing-photo-banner'), 'banner must not render when every dish already has a photo');
    }
}
