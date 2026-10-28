<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SubscriptionGate: an expired restaurant's owner and staff get only the
 * "subscription expired" page on any /admin route (checked per request, so
 * an already-logged-in session is cut off too), and a super-admin is sent
 * from /admin to /platform.
 */
final class SubscriptionGateTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Subscription Gate Test Restaurant');
        $this->restaurant->setSlug('subscription-gate-test-' . uniqid());
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->setAdminLocale('es');
        $this->em->persist($this->restaurant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $user) {
            $this->em->remove($this->em->find(User::class, $user->getId()));
        }
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function user(string $role, ?Restaurant $restaurant): User
    {
        $user = new User();
        $user->setEmail('subscription-gate-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([$role]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        return $this->users[] = $user;
    }

    private function expire(): void
    {
        $this->restaurant->deactivateNow();
        $this->em->flush();
    }

    public function testActiveRestaurantReachesAdmin(): void
    {
        $this->client->loginUser($this->user(User::ROLE_OWNER, $this->restaurant));
        $this->client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
    }

    public function testExpiredOwnerGetsOnlyTheExpiredPage(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->restaurant);
        $this->expire();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/admin/settings');

        self::assertResponseStatusCodeSame(403);
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('d/m/Y');
        self::assertSelectorTextSame('.message', 'Tu suscripción venció el ' . $yesterday);
        self::assertSelectorNotExists('.sidebar');
    }

    public function testExpiredStaffIsBlockedToo(): void
    {
        $staff = $this->user(User::ROLE_STAFF, $this->restaurant);
        $this->expire();

        $this->client->loginUser($staff);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAlreadyOpenSessionIsCutOffOnNextRequest(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->restaurant);
        $this->client->loginUser($owner);
        $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();

        $this->expire();

        $this->client->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExpiredPageFollowsAdminLocale(): void
    {
        $this->restaurant->setAdminLocale('en');
        $owner = $this->user(User::ROLE_OWNER, $this->restaurant);
        $this->expire();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/admin/menu');

        self::assertSelectorTextContains('.message', 'Your subscription expired on');
    }

    public function testExpiredPostIsBlocked(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->restaurant);
        $this->expire();

        $this->client->loginUser($owner);
        $this->client->request('POST', '/admin/theme/apply');

        self::assertResponseStatusCodeSame(403);
    }

    public function testExpiredUserCanStillLogOut(): void
    {
        $owner = $this->user(User::ROLE_OWNER, $this->restaurant);
        $this->expire();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/admin/logout');

        self::assertResponseRedirects('/admin/login');
    }

    public function testSuperAdminIsRedirectedFromAdminToPlatform(): void
    {
        $this->client->loginUser($this->user(User::ROLE_SUPER_ADMIN, null));
        $this->client->request('GET', '/admin/menu');

        self::assertResponseRedirects('/platform');
    }
}
