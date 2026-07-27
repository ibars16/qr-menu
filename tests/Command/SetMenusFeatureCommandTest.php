<?php

namespace App\Tests\Command;

use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Coverage for app:restaurant:set-menus — on, off, and the two error paths
 * (unknown slug, invalid state). Needs the kernel (real EntityManager,
 * real command wiring), unlike this project's other, pure-unit tests.
 */
final class SetMenusFeatureCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Restaurant $restaurant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Set Menus Command Test Restaurant');
        $this->restaurant->setSlug('set-menus-command-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->setAdminLocale('es');
        $this->restaurant->setLayout('standard');
        $this->restaurant->setTheme('classic-dark');
        $this->em->persist($this->restaurant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->remove($this->restaurant);
        $this->em->flush();
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        $application = new Application(static::$kernel);
        return new CommandTester($application->find('app:restaurant:set-menus'));
    }

    public function testOnEnablesTheFlag(): void
    {
        self::assertFalse($this->restaurant->isSetMenusEnabled());

        $tester = $this->tester();
        $exitCode = $tester->execute(['slug' => $this->restaurant->getSlug(), 'state' => 'on']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('off → on', $tester->getDisplay());

        $this->em->refresh($this->restaurant);
        self::assertTrue($this->restaurant->isSetMenusEnabled());
    }

    public function testOffDisablesTheFlag(): void
    {
        $this->restaurant->setSetMenusEnabled(true);
        $this->em->flush();

        $tester = $this->tester();
        $exitCode = $tester->execute(['slug' => $this->restaurant->getSlug(), 'state' => 'off']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('on → off', $tester->getDisplay());

        $this->em->refresh($this->restaurant);
        self::assertFalse($this->restaurant->isSetMenusEnabled());
    }

    public function testUnknownSlugFailsCleanly(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['slug' => 'this-slug-does-not-exist', 'state' => 'on']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testInvalidStateFailsCleanly(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['slug' => $this->restaurant->getSlug(), 'state' => 'maybe']);

        self::assertSame(1, $exitCode);

        $this->em->refresh($this->restaurant);
        self::assertFalse($this->restaurant->isSetMenusEnabled());
    }
}
