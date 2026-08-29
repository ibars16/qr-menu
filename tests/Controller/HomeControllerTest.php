<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HomeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnonymousVisitorSeesTheLandingPageWithASignupCta(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('href="/register"', $content);
    }

    public function testDefaultsToSpanishWithNoLanguageSignal(): void
    {
        // BrowserKit's test client sends "Accept-Language: en-us,en;q=0.5" by
        // default (confirmed by inspecting the request headers directly) —
        // must be overridden to actually exercise the no-signal default.
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => '']);
        self::assertStringContainsString('siempre actualizada', $this->client->getResponse()->getContent());
    }

    public function testLangQueryParamOverridesBrowserLanguage(): void
    {
        $this->client->request('GET', '/?lang=en', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertStringContainsString('always up to date', $this->client->getResponse()->getContent());
    }

    public function testUnsupportedLangQueryFallsBackToDefault(): void
    {
        $this->client->request('GET', '/?lang=zz', server: ['HTTP_ACCEPT_LANGUAGE' => '']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('siempre actualizada', $this->client->getResponse()->getContent());
    }

    public function testDetectsSupportedBrowserLanguageWhenNoQueryParamGiven(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);
        self::assertStringContainsString('toujours à jour', $this->client->getResponse()->getContent());
    }

    public function testLoggedInOwnerIsRedirectedToTheAdminPanel(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Home Test Restaurant');
        $restaurant->setSlug('home-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $owner = new User();
        $owner->setEmail('home-test-' . uniqid() . '@example.test');
        $owner->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($owner, 'irrelevant-password-1'));
        $owner->setRoles([User::ROLE_OWNER]);
        $owner->setRestaurant($restaurant);
        $this->em->persist($owner);

        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/admin/menu');

        $this->em->remove($owner);
        $this->em->remove($restaurant);
        $this->em->flush();
    }
}
