<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * With an empty carta every admin screen redirects to /admin/menu; building
 * the carta, billing and non-HTML requests are left alone.
 */
final class EmptyMenuRedirectTest extends WebTestCase
{
    private const HTML = ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*;q=0.8'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $restaurantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Empty Menu Redirect Test');
        $restaurant->setSlug('empty-menu-redirect-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('empty-menu-redirect-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        if ($restaurant) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($restaurant->getCategories() as $category) {
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        parent::tearDown();
    }

    public static function otherScreens(): array
    {
        return [
            ['/admin/settings'],
            ['/admin/estadisticas'],
            ['/admin/qr'],
            ['/admin/theme'],
            ['/admin/usuarios'],
        ];
    }

    #[DataProvider('otherScreens')]
    public function testEmptyCartaRedirectsOtherScreensToCarta(string $path): void
    {
        $this->client->request('GET', $path, server: self::HTML);

        self::assertResponseRedirects('/admin/menu');
    }

    public function testCartaImportAndBillingStayReachable(): void
    {
        $this->client->request('GET', '/admin/menu', server: self::HTML);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/menu/import', server: self::HTML);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/billing', server: self::HTML);
        self::assertFalse($this->client->getResponse()->isRedirect('/admin/menu'));
    }

    public function testNonHtmlRequestsAreNotRedirected(): void
    {
        $this->client->request('GET', '/admin/settings', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertFalse($this->client->getResponse()->isRedirect('/admin/menu'));
    }

    public function testCartaWithACategoryNoLongerRedirects(): void
    {
        $this->client->request('POST', '/admin/categories/create', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Entrantes']));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/settings', server: self::HTML);

        self::assertResponseIsSuccessful();
    }
}
