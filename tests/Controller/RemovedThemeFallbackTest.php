<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * classic-warm and terra were removed: a restaurant row still carrying one
 * is left as is, renders as maison on the public menu, and /admin/theme
 * shows maison as the active card without offering the removed ones.
 */
final class RemovedThemeFallbackTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $toRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->toRemove) as $entity) {
            $entity = $this->em->find($entity::class, $entity->getId());
            if ($entity) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    public static function removedThemes(): iterable
    {
        yield 'classic-warm' => ['classic-warm'];
        yield 'terra' => ['terra'];
    }

    private function makeRestaurant(string $theme): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Tema');
        $restaurant->setSlug('removed-theme-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme($theme);
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->toRemove[] = $restaurant;

        return $restaurant;
    }

    #[DataProvider('removedThemes')]
    public function testPublicMenuRendersAsMaisonAndLeavesTheRowAlone(string $theme): void
    {
        $restaurant = $this->makeRestaurant($theme);
        $id = $restaurant->getId();

        $html = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('--accent: #7A1E28;', $html, 'maison\'s palette');

        $this->em->clear();
        self::assertSame($theme, $this->em->find(Restaurant::class, $id)->getTheme(), 'the stored value is not rewritten');
    }

    #[DataProvider('removedThemes')]
    public function testAdminShowsMaisonActiveAndNoLongerOffersTheRemovedTheme(string $theme): void
    {
        $restaurant = $this->makeRestaurant($theme);

        $owner = new User();
        $owner->setEmail('removed-theme-test-' . uniqid() . '@example.test');
        $owner->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($owner, 'irrelevant-password-1'));
        $owner->setRoles([User::ROLE_OWNER]);
        $owner->setRestaurant($restaurant);
        $this->em->persist($owner);
        $this->em->flush();
        $this->toRemove[] = $owner;

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/admin/theme');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#tcard-maison.is-active'));
        self::assertCount(1, $crawler->filter('.sel-card.is-active'));
        self::assertCount(0, $crawler->filter('#tcard-' . $theme));
        self::assertCount(6, $crawler->filter('[id^="tcard-"]'));
    }
}
