<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * An email is the same whatever its case or surrounding spaces: stored
 * lower-cased (User::setEmail()), looked up with LOWER() (UserRepository)
 * — sign-up, login, "email already taken" and "forgot password".
 */
final class EmailCaseInsensitiveTest extends WebTestCase
{
    private const PASSWORD = 'a-strong-password-1';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->id = uniqid();
        static::getContainer()->get('limiter.reset_password_ip')->create('127.0.0.1')->reset();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $restaurantIds = $conn->fetchFirstColumn('SELECT restaurant_id FROM "user" WHERE LOWER(email) LIKE \'email-case-%\' AND restaurant_id IS NOT NULL');
        $conn->executeStatement('DELETE FROM reset_password_request WHERE user_id IN (SELECT id FROM "user" WHERE LOWER(email) LIKE \'email-case-%\')');
        $conn->executeStatement('DELETE FROM "user" WHERE LOWER(email) LIKE \'email-case-%\'');
        foreach ($restaurantIds as $restaurantId) {
            $this->em->remove($this->em->find(\App\Entity\Restaurant::class, $restaurantId));
        }
        $this->em->flush();
        static::getContainer()->get('limiter.reset_password_ip')->create('127.0.0.1')->reset();

        parent::tearDown();
    }

    public function testTheEmailIsStoredLowerCasedAndTrimmed(): void
    {
        $user = new User();
        $user->setEmail('  Email-Case-' . $this->id . '@Gmail.COM ');

        self::assertSame('email-case-' . $this->id . '@gmail.com', $user->getEmail());
    }

    public function testSignUpWithCapitalsThenLogInInLowerCase(): void
    {
        $this->register('Email-Case-' . $this->id . '@Gmail.com');
        self::assertResponseRedirects();

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneByEmail('email-case-' . $this->id . '@gmail.com');
        self::assertNotNull($user);
        self::assertSame('email-case-' . $this->id . '@gmail.com', $user->getEmail(), 'stored lower-cased');

        $this->freshClient();
        $this->logIn('email-case-' . $this->id . '@gmail.com');
        self::assertResponseRedirects('http://localhost/admin/menu');
    }

    public function testTheSameEmailInOtherCapitalsIsTaken(): void
    {
        $this->register('email-case-' . $this->id . '@gmail.com');
        self::assertResponseRedirects();

        $this->freshClient();
        $this->register('EMAIL-CASE-' . $this->id . '@gmail.com');
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM "user" WHERE LOWER(email) = ?', ['email-case-' . $this->id . '@gmail.com']);
        self::assertSame(1, $count, 'no second account');
    }

    /** A row saved before the rule (mixed case, no migration) is still found by login and "forgot password". */
    public function testAnOldMixedCaseRowStillLogsInAndGetsTheResetLink(): void
    {
        $user = new User();
        $user->setEmail('placeholder-' . $this->id . '@example.test');
        $user->setRoles([User::ROLE_SUPER_ADMIN]);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE "user" SET email = ? WHERE id = ?', ['Email-Case-' . $this->id . '@Example.test', $user->getId()]);

        $this->logIn('email-case-' . $this->id . '@example.test');
        self::assertResponseRedirects('http://localhost/admin/menu');

        $this->freshClient();
        $crawler = $this->client->request('GET', '/admin/login/recuperar');
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['form[email]'] = 'EMAIL-CASE-' . $this->id . '@EXAMPLE.TEST';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/login/recuperar');

        $sent = array_values(array_filter(self::getMailerEvents(), static fn ($e) => !$e->isQueued()));
        self::assertCount(1, $sent, 'the reset link is sent');
        self::assertSame('Email-Case-' . $this->id . '@Example.test', $sent[0]->getMessage()->getTo()[0]->getAddress());
    }

    public function testForgotPasswordWithCapitalsFindsTheAccount(): void
    {
        $user = new User();
        $user->setEmail('email-case-' . $this->id . '@example.test');
        $user->setRoles([User::ROLE_SUPER_ADMIN]);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/login/recuperar');
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['form[email]'] = ' Email-Case-' . $this->id . '@Example.TEST ';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/login/recuperar');

        $sent = array_values(array_filter(self::getMailerEvents(), static fn ($e) => !$e->isQueued()));
        self::assertCount(1, $sent, 'the reset link is sent');
        self::assertSame('email-case-' . $this->id . '@example.test', $sent[0]->getMessage()->getTo()[0]->getAddress());
    }

    private function register(string $email): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Email Case Test ' . $this->id;
        $form['registration_form[email]'] = $email;
        $form['registration_form[password][first]'] = self::PASSWORD;
        $form['registration_form[password][second]'] = self::PASSWORD;
        $form['registration_form[currency]'] = 'EUR';
        $form['registration_form[language]'] = 'es';
        $form['registration_form[adminLocale]'] = 'es';
        $this->client->submit($form);
    }

    private function logIn(string $email): void
    {
        $page = $this->client->request('GET', '/admin/login');
        $this->client->submit($page->filter('form.login-form')->form(['email' => $email, 'password' => self::PASSWORD]));
    }

    private function freshClient(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->restart();
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'es');
    }
}
