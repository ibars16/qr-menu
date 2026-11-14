<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Importar carta con IA" upload that arrives with no files. PHP drops
 * $_FILES entirely when the body is over post_max_size, so a too-heavy
 * upload must say so instead of "Por favor, elige al menos una foto." —
 * which stays for a genuinely empty submit. Neither path creates a batch
 * or stores anything.
 */
final class MenuImportUploadEmptyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var int[] */
    private array $restaurantIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIds as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
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

    private function loginOwner(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Import empty test');
        $restaurant->setSlug('import-empty-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIds[] = $restaurant->getId();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('import-empty-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    public function testBodyOverPostMaxSizeSaysPhotosAreTooHeavy(): void
    {
        if (in_array(trim((string) ini_get('post_max_size')), ['', '0'], true)) {
            self::markTestSkipped('post_max_size is unlimited here.');
        }
        $this->loginOwner();

        // Far over any real post_max_size: what PHP saw before dropping $_FILES.
        $this->client->request('POST', '/admin/menu/import/upload', server: ['CONTENT_LENGTH' => (string) (200 * 1024 ** 3)]);

        self::assertResponseRedirects('/admin/menu/import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Las fotos pesan demasiado en total. Súbelas en varias veces (máximo unos 60 MB por envío).');
        self::assertSelectorTextNotContains('.flash-error', 'elige al menos una foto');
    }

    public function testEmptySubmitUnderTheLimitKeepsTheUsualMessage(): void
    {
        $this->loginOwner();

        $this->client->request('POST', '/admin/menu/import/upload', server: ['CONTENT_LENGTH' => '1200']);

        self::assertResponseRedirects('/admin/menu/import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Por favor, elige al menos una foto.');
        self::assertSelectorTextNotContains('.flash-error', 'pesan demasiado');
    }

    public function testUploadScreenShowsTheLimitsHint(): void
    {
        $this->loginOwner();

        $this->client->request('GET', '/admin/menu/import');

        self::assertSelectorTextContains('.import-drop-hint', 'Hasta 30 páginas (fotos o PDF), de 15 MB como máximo cada archivo.');
    }
}
