<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional coverage for AdminLocaleSubscriber — confirms a restaurant's
 * own Restaurant::$adminLocale actually drives the rendered Admin Panel
 * language end to end (real HTTP request through the real kernel/security
 * layer, not just a unit test of the subscriber in isolation), mirroring
 * SetMenusFeatureGateTest's approach for the sibling kernel.request gate.
 */
final class AdminLocaleSubscriberTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Admin Locale Test Restaurant');
        $this->restaurant->setSlug('admin-locale-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->setAdminLocale('en');
        $this->restaurant->setLayout('standard');
        $this->restaurant->setTheme('classic-dark');
        $this->em->persist($this->restaurant);

        $this->user = new User();
        $this->user->setEmail('admin-locale-test-' . uniqid() . '@example.test');
        $this->user->setPassword('unused-in-tests');
        $this->user->setRoles([User::ROLE_OWNER]);
        $this->user->setRestaurant($this->restaurant);
        $this->em->persist($this->user);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->remove($this->user);
        $this->em->remove($this->restaurant);
        $this->em->flush();

        parent::tearDown();
    }

    public function testRestaurantsAdminLocaleDrivesTheRenderedPageLanguage(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'), 'the <html lang> attribute must follow the restaurant\'s own adminLocale');
        self::assertStringContainsString('Settings', $crawler->filter('title')->text(), 'admin_settings.en.yaml\'s title, not the Spanish default');
        self::assertStringNotContainsString('Configuración', $this->client->getResponse()->getContent());
    }

    public function testChangingTheRestaurantsAdminLocaleChangesTheNextRequestsLanguage(): void
    {
        $this->restaurant->setAdminLocale('fr');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('Paramètres', $crawler->filter('title')->text());
    }
}
