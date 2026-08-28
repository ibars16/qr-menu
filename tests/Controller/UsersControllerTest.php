<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional coverage for UsersController (team management) and the
 * Owner/ROLE_STAFF access-control split it depends on. The two safety
 * checks worth pinning down: a restaurant can never end up with zero
 * Owners, and a staff account can never reach an Owner-only screen.
 */
final class UsersControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;

    /** @var int[] restaurant ids, not entities — some tests call $em->clear(), which detaches everything created before it */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIdsToRemove as $restaurantId) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($restaurantId);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function makeRestaurant(string $name): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setSlug('users-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush(); // assigns the id immediately — see $restaurantIdsToRemove's own comment
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $restaurant;
    }

    private function makeUser(Restaurant $restaurant, string $role, ?string $emailOverride = null): User
    {
        $user = new User();
        $user->setEmail($emailOverride ?? ('users-test-' . uniqid() . '@example.test'));
        $user->setPassword($this->hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([$role]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);

        return $user;
    }

    public function testOwnerCanCreateEditAndDeleteAStaffUser(): void
    {
        $restaurant = $this->makeRestaurant('Owner CRUD Test');
        $owner = $this->makeUser($restaurant, User::ROLE_OWNER);
        $this->em->flush();

        $this->client->loginUser($owner);

        $newEmail = 'staff-crud-' . uniqid() . '@example.test';
        $this->client->request(
            'POST', '/admin/usuarios/crear', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $newEmail, 'password' => 'a-long-enough-password', 'firstName' => 'Ana', 'lastName' => 'Pérez', 'role' => 'staff'])
        );
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $staff = $this->em->getRepository(User::class)->findOneBy(['email' => $newEmail]);
        self::assertNotNull($staff);
        self::assertContains(User::ROLE_STAFF, $staff->getRoles());
        self::assertNotContains(User::ROLE_OWNER, $staff->getRoles());

        // Re-fetch the owner in this cleared EM to keep the session's User reference valid.
        $owner = $this->em->getRepository(User::class)->find($owner->getId());
        $this->client->loginUser($owner);

        $this->client->request(
            'POST', "/admin/usuarios/{$staff->getId()}/editar", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['firstName' => 'Ana', 'lastName' => 'Gómez', 'role' => 'owner'])
        );
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $staff = $this->em->getRepository(User::class)->findOneBy(['email' => $newEmail]);
        self::assertContains(User::ROLE_OWNER, $staff->getRoles());
        self::assertSame('Gómez', $staff->getLastName());

        $owner = $this->em->getRepository(User::class)->find($owner->getId());
        $this->client->loginUser($owner);
        $this->client->request('POST', "/admin/usuarios/{$staff->getId()}/eliminar");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $newEmail]));
    }

    public function testCreatingAUserWithADuplicateEmailIsRejected(): void
    {
        $restaurant = $this->makeRestaurant('Duplicate Email Test');
        $owner = $this->makeUser($restaurant, User::ROLE_OWNER);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(
            'POST', '/admin/usuarios/crear', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $owner->getEmail(), 'password' => 'a-long-enough-password', 'role' => 'staff'])
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testCannotDemoteTheOnlyOwnerToStaff(): void
    {
        $restaurant = $this->makeRestaurant('Last Owner Demote Test');
        $owner = $this->makeUser($restaurant, User::ROLE_OWNER);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(
            'POST', "/admin/usuarios/{$owner->getId()}/editar", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['firstName' => '', 'lastName' => '', 'role' => 'staff'])
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $owner = $this->em->getRepository(User::class)->find($owner->getId());
        self::assertContains(User::ROLE_OWNER, $owner->getRoles());
    }

    public function testCannotDeleteYourOwnAccount(): void
    {
        $restaurant = $this->makeRestaurant('Self Delete Test');
        $owner = $this->makeUser($restaurant, User::ROLE_OWNER);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('POST', "/admin/usuarios/{$owner->getId()}/eliminar");

        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(User::class)->find($owner->getId()));
    }

    public function testCannotEditOrDeleteAUserFromAnotherRestaurant(): void
    {
        $restaurantA = $this->makeRestaurant('Restaurant A');
        $ownerA = $this->makeUser($restaurantA, User::ROLE_OWNER);

        $restaurantB = $this->makeRestaurant('Restaurant B');
        $staffB = $this->makeUser($restaurantB, User::ROLE_STAFF);
        $this->em->flush();

        $this->client->loginUser($ownerA);

        $this->client->request(
            'POST', "/admin/usuarios/{$staffB->getId()}/editar", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['firstName' => 'x', 'lastName' => 'y', 'role' => 'owner'])
        );
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', "/admin/usuarios/{$staffB->getId()}/eliminar");
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testStaffRoleCanReachContentScreensButNotOwnerOnlyScreens(): void
    {
        $restaurant = $this->makeRestaurant('Access Control Test');
        $staff = $this->makeUser($restaurant, User::ROLE_STAFF);
        $owner = $this->makeUser($restaurant, User::ROLE_OWNER);
        $this->em->flush();

        $this->client->loginUser($staff);
        $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();

        foreach (['/admin/settings', '/admin/qr', '/admin/usuarios'] as $ownerOnlyPath) {
            $this->client->request('GET', $ownerOnlyPath);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), "staff must be forbidden from {$ownerOnlyPath}");
        }

        $this->client->loginUser($owner);
        foreach (['/admin/menu', '/admin/settings', '/admin/qr', '/admin/usuarios'] as $ownerPath) {
            $this->client->request('GET', $ownerPath);
            self::assertResponseIsSuccessful("owner must reach {$ownerPath}");
        }
    }
}
