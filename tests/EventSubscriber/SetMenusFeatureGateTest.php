<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Category;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional coverage for SetMenusFeatureGate — the kernel.controller
 * listener that gates every Admin\MenusController action behind
 * Restaurant::$setMenusEnabled — plus the sidebar visibility it backs and
 * the existing-data-safety guarantee (gating a restaurant's menu-categories
 * never touches the underlying rows). Needs the real test DB (a session +
 * an authenticated user), unlike this project's other, pure-unit tests —
 * that's inherent to testing routing/rendering, not avoidable here.
 */
final class SetMenusFeatureGateTest extends WebTestCase
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
        $this->restaurant->setName('Set Menus Gate Test Restaurant');
        $this->restaurant->setSlug('set-menus-gate-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->setAdminLocale('es');
        $this->restaurant->setLayout('standard');
        $this->restaurant->setTheme('classic-dark');
        $this->em->persist($this->restaurant);

        $this->user = new User();
        $this->user->setEmail('set-menus-gate-test-' . uniqid() . '@example.test');
        $this->user->setPassword('unused-in-tests');
        $this->user->setRoles(['ROLE_USER']);
        $this->user->setRestaurant($this->restaurant);
        $this->em->persist($this->user);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $this->restaurant]) as $category) {
            $this->em->remove($category);
        }
        $this->em->remove($this->user);
        $this->em->remove($this->restaurant);
        $this->em->flush();

        parent::tearDown();
    }

    public function testMenusRouteIs404WhenFlagOff(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/menus');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMenusRouteIs200WhenFlagOn(): void
    {
        $this->restaurant->setSetMenusEnabled(true);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/menus');

        self::assertResponseIsSuccessful();
    }

    public function testMenusCreateRouteIs404WhenFlagOff(): void
    {
        // A non-GET, non-{id} action on the same controller — confirms the
        // gate covers the whole controller, not just its index action.
        $this->client->loginUser($this->user);
        $this->client->request(
            'POST',
            '/admin/menus/create',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'x', 'menuPrice' => '10'])
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testSidebarHidesSetMenusLinkWhenFlagOff(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/menus"]');
    }

    public function testSidebarShowsSetMenusLinkWhenFlagOn(): void
    {
        $this->restaurant->setSetMenusEnabled(true);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/menus"]');
    }

    public function testExistingMenuCategoryDataIsUntouchedButGatedWhenFlagOff(): void
    {
        $category = new Category();
        $category->setRestaurant($this->restaurant);
        $category->setPosition(0);
        $category->setActive(true);
        $category->setMenuPrice(1500);
        $this->em->persist($category);
        $this->em->flush();
        $categoryId = $category->getId();

        $this->client->loginUser($this->user);
        $this->client->request('GET', "/admin/menus/{$categoryId}");
        self::assertResponseStatusCodeSame(404);

        // Gating is access control only — force a real DB round-trip (not
        // just re-reading the identity-mapped PHP object) to confirm the
        // row itself is untouched.
        $this->em->refresh($category);
        self::assertSame(1500, $category->getMenuPrice());
    }
}
