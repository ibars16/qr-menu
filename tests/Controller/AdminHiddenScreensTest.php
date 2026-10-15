<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Categorías and Etiquetas admin screens are hidden (no sidebar or
 * bottom-nav link, 404 by URL), while their POST endpoints stay live — the
 * Carta screen and the dish editor create/edit categories and tags through
 * them.
 */
final class AdminHiddenScreensTest extends WebTestCase
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

        $restaurant = new Restaurant();
        $restaurant->setName('Hidden Screens Test');
        $restaurant->setSlug('hidden-screens-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('hidden-screens-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

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
            foreach ($restaurant->getProductTags() as $tag) {
                $this->em->remove($tag);
            }
            foreach ($restaurant->getCategories() as $category) {
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testCategoriesAndTagsScreensReturn404(): void
    {
        $this->client->request('GET', '/admin/categories');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/tags');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminNavNoLongerLinksToThem(): void
    {
        $crawler = $this->client->request('GET', '/admin/theme');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/admin/categories"]'));
        self::assertCount(0, $crawler->filter('a[href="/admin/tags"]'));
    }

    public function testMobileBottomNavLinksToTheThemeScreen(): void
    {
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.bottom-nav a.bottom-nav-tab[href="/admin/theme"]'));

        $crawler = $this->client->request('GET', '/admin/theme');
        self::assertCount(1, $crawler->filter('.bottom-nav a.bottom-nav-tab.active[href="/admin/theme"]'));
    }

    public function testCategoryAndTagCreateEndpointsStillWork(): void
    {
        $this->client->request('POST', '/admin/categories/create', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Entrantes']));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/admin/tags/create', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Casero']));
        self::assertResponseIsSuccessful();
    }
}
