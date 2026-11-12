<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The owner's theme/layout preview banner on the public menu speaks the
 * owner's panel language (Restaurant::$adminLocale), not the menu's.
 */
final class MenuPreviewBannerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Casa Preview');
        $this->restaurant->setSlug('preview-banner-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->setTheme('maison');
        $this->restaurant->setLayout('standard');
        $this->em->persist($this->restaurant);

        $this->owner = new User();
        $this->owner->setEmail('preview-banner-test-' . uniqid() . '@example.test');
        $this->owner->setPassword('unused-in-tests');
        $this->owner->setRoles([User::ROLE_OWNER]);
        $this->owner->setRestaurant($this->restaurant);
        $this->em->persist($this->owner);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->remove($this->em->find(User::class, $this->owner->getId()));
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function previewText(string $adminLocale): string
    {
        $this->restaurant->setAdminLocale($adminLocale);
        $this->em->flush();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es&preview_theme=maison');
        self::assertResponseIsSuccessful();
        self::assertSame('es', $crawler->filter('body')->attr('data-menu-lang'), 'the menu itself stays in its own language');

        return $crawler->filter('a[href="/admin/theme"]')->ancestors()->first()->text();
    }

    public function testBannerInSpanishPanel(): void
    {
        $text = $this->previewText('es');

        self::assertStringContainsString('Modo previsualización', $text);
        self::assertStringContainsString('Solo tú ves este tema. Tus clientes siguen viendo el tema activo.', $text);
        self::assertStringContainsString('← Volver al admin', $text);
    }

    public function testBannerInThePanelLanguageNotTheMenus(): void
    {
        $text = $this->previewText('en');

        self::assertStringContainsString('Preview mode', $text);
        self::assertStringContainsString('Only you can see this theme. Your customers still see the active theme.', $text);
        self::assertStringContainsString('← Back to admin', $text);
        self::assertStringNotContainsString('Modo previsualización', $text);
    }

    public function testNoBannerWithoutPreview(): void
    {
        $this->client->loginUser($this->owner);
        $html = $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es')->html();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Modo previsualización', $html);
    }
}
