<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A reseller's customer signup link (/alta/{code}): the /register form with
 * "Alta con {empresa}" and nothing of ours with a price; the restaurant
 * belongs to the reseller, starts with 30 days, counts for the extra line
 * and its owner lands in the panel. Unknown code or expired reseller: "not
 * available", nothing created. Also /register, now translated.
 */
final class ResellerSignupTest extends WebTestCase
{
    private const BASE_MONTHLY = 'price_test_reseller_monthly';
    private const EXTRA_MONTHLY = 'price_test_reseller_extra_monthly';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeStripeGateway::reset();

        $this->reseller = new Reseller('Reseller Signup Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($this->reseller);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'reseller-signup-test-%\'');
        foreach ($this->em->getRepository(Restaurant::class)->createQueryBuilder('r')->where('r.slug LIKE :p')->setParameter('p', 'reseller-signup-test-%')->getQuery()->getResult() as $restaurant) {
            $this->em->remove($restaurant);
        }
        $this->em->flush();
        $conn->executeStatement("DELETE FROM reseller WHERE name LIKE 'Reseller Signup Test%'");

        parent::tearDown();
    }

    private function path(): string
    {
        return '/alta/' . $this->reseller->getSignupCode();
    }

    private function submit(Crawler $crawler, string $name, string $email): Crawler
    {
        $form = $crawler->filter('form')->form();
        $form['registration_form[restaurantName]'] = $name;
        $form['registration_form[email]'] = $email;
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[currency]'] = 'GBP';
        $form['registration_form[language]'] = 'fr';
        $form['registration_form[adminLocale]'] = 'en';

        return $this->client->submit($form);
    }

    private function restaurantNamed(string $name): ?Restaurant
    {
        $this->em->clear();

        return $this->em->getRepository(Restaurant::class)->findOneBy(['name' => $name]);
    }

    private function expire(): void
    {
        $this->em->clear();
        $this->em->find(Reseller::class, $this->reseller->getId())->deactivateNow();
        $this->em->flush();
    }

    public function testEveryResellerGetsItsOwnCode(): void
    {
        $other = new Reseller('Reseller Signup Test Other');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->reseller->getSignupCode());
        self::assertNotSame($this->reseller->getSignupCode(), $other->getSignupCode());
    }

    public function testPageSaysWhoAndShowsNoTrialOrHomeLink(): void
    {
        $crawler = $this->client->request('GET', $this->path());

        self::assertResponseIsSuccessful();
        self::assertSame('Alta con Reseller Signup Test Agency', $crawler->filter('#signup-reseller')->text());
        self::assertStringNotContainsString('prueba gratuita', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->filter('a[href="/"]'), 'no link to the home page and its prices');
        // Same form as /register, with the currency selector.
        self::assertCount(1, $crawler->filter('select[name="registration_form[currency]"]'));
    }

    public function testSignupCreatesTheResellersRestaurantAndEntersThePanel(): void
    {
        $email = 'reseller-signup-test-owner-' . uniqid() . '@example.test';
        $this->submit($this->client->request('GET', $this->path()), 'Reseller Signup Test Bistro', $email);

        self::assertResponseRedirects();
        $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();

        $restaurant = $this->restaurantNamed('Reseller Signup Test Bistro');
        self::assertNotNull($restaurant);
        self::assertSame($this->reseller->getId(), $restaurant->getReseller()?->getId());
        self::assertSame((new \DateTimeImmutable('today +30 days'))->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame('GBP', $restaurant->getCurrency());
        self::assertSame('fr', $restaurant->getDefaultLanguage());
        self::assertSame('en', $restaurant->getAdminLocale());

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertContains(User::ROLE_OWNER, $user->getRoles());
        self::assertSame($restaurant->getId(), $user->getRestaurant()?->getId());
    }

    public function testSignupCountsForTheExtraLine(): void
    {
        FakeStripeGateway::price(self::BASE_MONTHLY, 4000, 'month');
        FakeStripeGateway::price(self::EXTRA_MONTHLY, 600, 'month');
        FakeStripeGateway::resellerSubscription('sub_rs', $this->reseller->getId(), self::BASE_MONTHLY, self::EXTRA_MONTHLY, 0, 'cus_rs');
        $this->em->clear();
        $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
        $reseller->setStripeCustomerId('cus_rs');
        $reseller->setStripeSubscriptionId('sub_rs');
        for ($i = 0; $i < 5; $i++) {
            $restaurant = new Restaurant();
            $restaurant->setName('Reseller Signup Test Existing ' . $i);
            $restaurant->setSlug('reseller-signup-test-existing-' . uniqid());
            $restaurant->setReseller($reseller);
            $this->em->persist($restaurant);
        }
        $this->em->flush();

        $this->submit($this->client->request('GET', $this->path()), 'Reseller Signup Test Sixth', 'reseller-signup-test-sixth-' . uniqid() . '@example.test');

        self::assertResponseRedirects();
        $updates = array_values(array_filter(FakeStripeGateway::$calls, static fn (array $call): bool => $call[0] === 'updateSubscription' && isset($call[2]['items'])));
        self::assertCount(1, $updates);
        self::assertSame([['price' => self::EXTRA_MONTHLY, 'quantity' => 1]], $updates[0][2]['items']);
    }

    public function testStripeFailureStillCreatesTheRestaurant(): void
    {
        FakeStripeGateway::resellerSubscription('sub_rs', $this->reseller->getId(), self::BASE_MONTHLY, self::EXTRA_MONTHLY, 0, 'cus_rs');
        $this->em->clear();
        $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
        $reseller->setStripeSubscriptionId('sub_rs');
        $this->em->flush();
        $crawler = $this->client->request('GET', $this->path());

        FakeStripeGateway::$fail = true;
        $this->submit($crawler, 'Reseller Signup Test Failing', 'reseller-signup-test-failing-' . uniqid() . '@example.test');

        self::assertResponseRedirects();
        self::assertNotNull($this->restaurantNamed('Reseller Signup Test Failing'));
    }

    public function testUnknownCodeIsUnavailable(): void
    {
        $crawler = $this->client->request('GET', '/alta/0000000000000000000000000000dead');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Este enlace no está disponible', $crawler->filter('#signup-unavailable .card-title')->text());
        self::assertCount(0, $crawler->filter('form'));
    }

    public function testExpiredResellerIsUnavailableAndCreatesNothing(): void
    {
        $crawler = $this->client->request('GET', $this->path());
        $form = $crawler->filter('form')->form();
        $this->expire();

        $crawler = $this->client->request('GET', $this->path());
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $crawler->filter('#signup-unavailable'));

        // A form opened before the reseller expired doesn't get through either.
        $form['registration_form[restaurantName]'] = 'Reseller Signup Test Late';
        $form['registration_form[email]'] = 'reseller-signup-test-late-' . uniqid() . '@example.test';
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->restaurantNamed('Reseller Signup Test Late'));
    }

    public function testTakenEmailShowsErrorAndCreatesNothing(): void
    {
        $existing = new User();
        $existing->setEmail('reseller-signup-test-taken-' . uniqid() . '@example.test');
        $existing->setPassword('unused-in-tests');
        $this->em->persist($existing);
        $this->em->flush();

        $page = $this->submit($this->client->request('GET', $this->path()), 'Reseller Signup Test Taken', $existing->getEmail());

        self::assertResponseIsUnprocessable();
        self::assertContains('Ya hay una cuenta con ese email.', $page->filter('.errors li')->each(fn ($li) => $li->text()));
        self::assertSame('Alta con Reseller Signup Test Agency', $page->filter('#signup-reseller')->text());
        self::assertNull($this->restaurantNamed('Reseller Signup Test Taken'));
    }

    public function testPageFollowsTheBrowserLanguage(): void
    {
        $crawler = $this->client->request('GET', $this->path(), server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertSame('Sign up with Reseller Signup Test Agency', $crawler->filter('#signup-reseller')->text());
        self::assertSame('Create your account', $crawler->filter('.card-title')->text());
        self::assertSame('en', $crawler->filter('select[name="registration_form[adminLocale]"] option[selected]')->attr('value'));
    }

    public function testRegisterIsTranslatedIncludingErrors(): void
    {
        $crawler = $this->client->request('GET', '/register', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSame('Créez votre compte', $crawler->filter('.card-title')->text());
        self::assertStringContainsString('essai gratuit', $crawler->filter('.card-sub')->text());
        self::assertSame('Livre sterling (£)', $crawler->filter('select[name="registration_form[currency]"] option[value="GBP"]')->text());

        $form = $crawler->filter('form')->form();
        $form['registration_form[restaurantName]'] = 'Reseller Signup Test Short';
        $form['registration_form[email]'] = 'reseller-signup-test-short@example.test';
        $form['registration_form[password][first]'] = 'short';
        $form['registration_form[password][second]'] = 'short';
        $page = $this->client->submit($form, serverParameters: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertResponseIsUnprocessable();
        $field = $page->filter('input[name="registration_form[password][first]"]')->closest('.field');
        self::assertSame(['8 caractères minimum.'], $field->filter('.errors li')->each(fn ($li) => $li->text()));
    }

    public function testPartnersPanelShowsTheLinkToCopy(): void
    {
        $user = new User();
        $user->setEmail('reseller-signup-test-reseller-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_RESELLER]);
        $user->setReseller($this->reseller);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/partners');
        self::assertResponseIsSuccessful();
        $url = 'http://localhost' . $this->path();
        self::assertSame('Tu enlace de alta para clientes', $crawler->filter('#signup-link .connect-title')->text());
        self::assertSame($url, $crawler->filter('#signup-link .signup-link-url')->text());
        self::assertSame($url, $crawler->filter('#signup-link-copy')->attr('data-url'));
        self::assertSame('Copiar enlace', $crawler->filter('#signup-link-copy')->text());
        self::assertNull($crawler->filter('#signup-link-copy')->attr('disabled'));

        $this->expire();
        $crawler = $this->client->request('GET', '/partners');
        self::assertNotNull($crawler->filter('#signup-link-copy')->attr('disabled'));
    }
}
