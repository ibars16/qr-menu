<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The welcome email (AccountMailer::sendWelcome): sent on the three
 * sign-ups — /register, /alta/{code}, /partners/registro — in the panel
 * language chosen there; never to staff (/admin/usuarios) nor to the owner
 * of a restaurant a reseller creates from /partners. A failing email never
 * breaks the sign-up.
 */
final class WelcomeEmailTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeStripeGateway::reset();
    }

    protected function tearDown(): void
    {
        // Users, then restaurants, then resellers: the foreign keys are RESTRICT.
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'welcome-test-%\'');
        foreach ($this->em->getRepository(Restaurant::class)->createQueryBuilder('r')->where('r.slug LIKE :p')->setParameter('p', 'welcome-test-%')->getQuery()->getResult() as $restaurant) {
            $this->em->remove($restaurant);
        }
        $this->em->flush();
        $conn->executeStatement("DELETE FROM reseller WHERE name LIKE 'Welcome Test%'");

        parent::tearDown();
    }

    /** @return Email[] emails actually sent by the last request (the mailer also logs each one as "queued" first) */
    private static function sent(): array
    {
        $events = array_filter(self::getMailerEvents(), static fn ($e) => !$e->isQueued());

        return array_values(array_map(static fn ($e) => $e->getMessage(), $events));
    }

    private static function email(): string
    {
        return 'welcome-test-' . uniqid() . '@example.test';
    }

    private function signUpRestaurant(string $path, string $email, string $adminLocale): void
    {
        $form = $this->client->request('GET', $path)->filter('form')->form();
        $form['registration_form[restaurantName]'] = 'Welcome Test ' . uniqid();
        $form['registration_form[email]'] = $email;
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[currency]'] = 'EUR';
        $form['registration_form[language]'] = 'es';
        $form['registration_form[adminLocale]'] = $adminLocale;
        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    private function reseller(): Reseller
    {
        $reseller = new Reseller('Welcome Test Agency');
        $reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($reseller);
        $this->em->flush();

        return $reseller;
    }

    private static function assertWelcome(string $to, string $subject): Email
    {
        $sent = self::sent();
        self::assertCount(1, $sent);
        self::assertSame($to, $sent[0]->getTo()[0]->getAddress());
        self::assertSame($subject, $sent[0]->getSubject());
        $html = $sent[0]->getHtmlBody();
        self::assertStringContainsString('href="http://localhost/admin/login"', $html, 'absolute link to the login');
        self::assertStringContainsString($to, $html, 'says which email they sign in with');

        return $sent[0];
    }

    public function testRegisterSendsItInThePanelLanguageChosen(): void
    {
        $email = self::email();
        $this->signUpRestaurant('/register', $email, 'it');

        $welcome = self::assertWelcome($email, 'Benvenuto su QR Menu');
        self::assertStringContainsString('Password dimenticata?', $welcome->getHtmlBody());
    }

    public function testRegisterSendsItInSpanish(): void
    {
        $email = self::email();
        $this->signUpRestaurant('/register', $email, 'es');

        $html = self::assertWelcome($email, 'Bienvenido a QR Menu')->getHtmlBody();
        self::assertStringContainsString('Tu cuenta ya está lista.', $html);
        self::assertStringContainsString('Entrar en tu panel', $html);
        foreach (['prueba', 'precio', 'pago', '€'] as $word) {
            self::assertStringNotContainsStringIgnoringCase($word, $html, 'nothing about trial, prices or payments');
        }
    }

    public function testResellerCustomerSignUpSendsIt(): void
    {
        $email = self::email();
        $this->signUpRestaurant('/alta/' . $this->reseller()->getSignupCode(), $email, 'pt');

        self::assertWelcome($email, 'Bem-vindo ao QR Menu');
    }

    public function testResellerSignUpSendsIt(): void
    {
        $email = self::email();
        $form = $this->client->request('GET', '/partners/registro')->filter('form')->form();
        $form['reseller_registration_form[company]'] = 'Welcome Test Signup Agency';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = $email;
        $form['reseller_registration_form[password][first]'] = 'a-strong-password-1';
        $form['reseller_registration_form[password][second]'] = 'a-strong-password-1';
        $form['reseller_registration_form[adminLocale]'] = 'de';
        $this->client->submit($form);
        self::assertResponseRedirects();

        self::assertWelcome($email, 'Willkommen bei QR Menu');
    }

    public function testStaffCreatedByTheOwnerGetsNothing(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Welcome Test Staff Restaurant');
        $restaurant->setSlug('welcome-test-' . uniqid());
        $this->em->persist($restaurant);
        $owner = new User();
        $owner->setEmail(self::email());
        $owner->setPassword('unused');
        $owner->setRoles([User::ROLE_OWNER]);
        $owner->setRestaurant($restaurant);
        $this->em->persist($owner);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('POST', '/admin/usuarios/crear', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => self::email(), 'password' => 'a-long-enough-password', 'role' => 'staff']));
        self::assertResponseIsSuccessful();

        self::assertCount(0, self::sent());
    }

    public function testRestaurantCreatedByAResellerFromItsPanelGetsNothing(): void
    {
        $reseller = $this->reseller();
        $resellerUser = new User();
        $resellerUser->setEmail(self::email());
        $resellerUser->setPassword('unused');
        $resellerUser->setRoles([User::ROLE_RESELLER]);
        $resellerUser->setReseller($reseller);
        $this->em->persist($resellerUser);
        $this->em->flush();

        $this->client->loginUser($resellerUser);
        $panel = $this->client->request('GET', '/partners');
        $email = self::email();
        $this->client->request('POST', '/partners/restaurantes', [
            '_token'   => $panel->filter('form.new-restaurant-form input[name="_token"]')->attr('value'),
            'name'     => 'Welcome Test Created By Reseller',
            'email'    => $email,
            'password' => 'a-strong-password-1',
        ]);
        self::assertResponseRedirects();

        self::assertNotNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]), 'the restaurant was created');
        self::assertCount(0, self::sent());
    }

    public function testSignUpWorksEvenIfTheEmailFails(): void
    {
        // A mailer that always fails, in place of the real one for this kernel.
        $failing = new class implements MailerInterface {
            public int $calls = 0;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                ++$this->calls;
                throw new TransportException('SMTP down');
            }
        };
        static::getContainer()->set(MailerInterface::class, $failing);
        $this->client->disableReboot();

        $email = self::email();
        $this->signUpRestaurant('/register', $email, 'es');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, 'the account exists');
        self::assertInstanceOf(Restaurant::class, $user->getRestaurant());
        self::assertResponseRedirects('/admin/menu', message: 'and the new owner is logged in as usual');
        self::assertSame(1, $failing->calls, 'the welcome email was attempted — and failed');
    }
}
