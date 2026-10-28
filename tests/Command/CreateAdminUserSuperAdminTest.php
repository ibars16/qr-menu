<?php

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:create-admin --super-admin: a platform owner with ROLE_SUPER_ADMIN and
 * no restaurant.
 */
final class CreateAdminUserSuperAdminTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;
    private string $email;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:create-admin'));
        $this->email = 'create-super-admin-test-' . uniqid() . '@example.test';
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        if ($user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email])) {
            $this->em->remove($user);
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testCreatesSuperAdminWithoutRestaurant(): void
    {
        $status = $this->tester->execute(['email' => $this->email, 'password' => 'secret', '--super-admin' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email]);
        self::assertNotNull($user);
        self::assertContains(User::ROLE_SUPER_ADMIN, $user->getRoles());
        self::assertNotContains(User::ROLE_OWNER, $user->getRoles());
        self::assertNull($user->getRestaurant());
    }

    public function testRejectsSuperAdminWithRestaurantSlug(): void
    {
        $status = $this->tester->execute(['email' => $this->email, 'password' => 'secret', 'restaurant-slug' => 'whatever', '--super-admin' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $this->email]));
    }
}
