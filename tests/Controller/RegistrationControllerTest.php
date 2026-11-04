<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The founding user of a brand-new restaurant must become its Owner — see the Owner/Staff split in UsersController and Version20260828064557's backfill for pre-existing accounts. */
final class RegistrationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?string $createdEmail = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The page follows the browser's language (no header = the first admin locale).
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $email = $this->createdEmail ?? null;
        if ($email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $restaurant = $user->getRestaurant();
                $this->em->remove($user);
                if ($restaurant) {
                    $this->em->remove($restaurant);
                }
                $this->em->flush();
            }
        }

        parent::tearDown();
    }

    public function testFoundingUserGetsOwnerRole(): void
    {
        $email = 'registration-test-' . uniqid() . '@example.test';
        $this->createdEmail = $email;

        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['registration_form[restaurantName]'] = 'Registration Test Restaurant';
        $form['registration_form[email]'] = $email;
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[currency]'] = 'EUR';
        $form['registration_form[language]'] = 'es';
        $form['registration_form[adminLocale]'] = 'es';

        $this->client->submit($form);

        self::assertResponseRedirects();

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, 'registration must have created the user');
        self::assertContains(User::ROLE_OWNER, $user->getRoles());
        self::assertInstanceOf(Restaurant::class, $user->getRestaurant());
        // Ours keep the free trial (a reseller's restaurant has none).
        self::assertSame((new \DateTimeImmutable('today +30 days'))->format('Y-m-d'), $user->getRestaurant()->getPaidUntil()->format('Y-m-d'));
    }

    public function testTakenEmailShowsErrorAndKeepsWhatWasTyped(): void
    {
        $existing = new User();
        $existing->setEmail('registration-taken-' . uniqid() . '@example.test');
        $existing->setPassword('unused-in-tests');
        $this->em->persist($existing);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['registration_form[restaurantName]'] = 'Registration Taken Restaurant';
        $form['registration_form[email]'] = $existing->getEmail();
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[currency]'] = 'GBP';
        $form['registration_form[language]'] = 'fr';
        $form['registration_form[adminLocale]'] = 'es';
        $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        $page = $this->client->getCrawler();
        self::assertContains('Ya hay una cuenta con ese email.', $page->filter('.errors li')->each(fn ($li) => $li->text()));
        self::assertSame('Registration Taken Restaurant', $page->filter('input[name="registration_form[restaurantName]"]')->attr('value'));
        self::assertSame($existing->getEmail(), $page->filter('input[name="registration_form[email]"]')->attr('value'));
        self::assertSame('GBP', $page->filter('select[name="registration_form[currency]"] option[selected]')->attr('value'));
        self::assertSame('fr', $page->filter('select[name="registration_form[language]"] option[selected]')->attr('value'));

        $this->em->clear();
        self::assertNull($this->em->getRepository(Restaurant::class)->findOneBy(['name' => 'Registration Taken Restaurant']));
        $this->em->remove($this->em->find(User::class, $existing->getId()));
        $this->em->flush();
    }

    public function testShortPasswordShowsNextToPasswordAndKeepsWhatWasTyped(): void
    {
        $crawler = $this->client->request('GET', '/register');
        // Turbo would reload this page (turbo-visit-control) and drop the errors.
        self::assertSame('false', $crawler->filter('form')->attr('data-turbo'));

        $form = $crawler->filter('form')->form();
        $form['registration_form[restaurantName]'] = 'Registration Short Restaurant';
        $form['registration_form[email]'] = 'registration-short@example.test';
        $form['registration_form[password][first]'] = 'short';
        $form['registration_form[password][second]'] = 'short';
        $form['registration_form[currency]'] = 'GBP';
        $page = $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        $field = $page->filter('input[name="registration_form[password][first]"]')->closest('.field');
        self::assertSame(['Mínimo 8 caracteres.'], $field->filter('.errors li')->each(fn ($li) => $li->text()));
        self::assertSame('Registration Short Restaurant', $page->filter('input[name="registration_form[restaurantName]"]')->attr('value'));
        self::assertSame('registration-short@example.test', $page->filter('input[name="registration_form[email]"]')->attr('value'));
        self::assertSame('GBP', $page->filter('select[name="registration_form[currency]"] option[selected]')->attr('value'));
        self::assertNull($page->filter('input[name="registration_form[password][first]"]')->attr('value'));
    }

    public function testExpiredPageShowsGeneralError(): void
    {
        $form = $this->client->request('GET', '/register')->filter('form')->form();
        $form['registration_form[_token]'] = 'expired';
        $form['registration_form[restaurantName]'] = 'Registration Expired Restaurant';
        $form['registration_form[email]'] = 'registration-expired@example.test';
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $page = $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame('La página ha caducado. Vuelve a enviar el formulario.', $page->filter('.form-errors[role="alert"] li')->text());
        self::assertSame('Registration Expired Restaurant', $page->filter('input[name="registration_form[restaurantName]"]')->attr('value'));
    }
}
