<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A failed login says what went wrong — no account with that email, or a
 * wrong password — and offers the way out (sign up / reset the password).
 *
 * The error lives in the session and is read once, so the form must never
 * go through Turbo: Turbo would follow the redirect by fetch (consuming the
 * error) and then, because of the page's turbo-visit-control=reload, reload
 * a page with nothing to show.
 */
final class LoginErrorTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->user) {
            $this->em->remove($this->em->find(User::class, $this->user->getId()));
            $this->em->flush();
        }
        parent::tearDown();
    }

    public function testAnUnknownEmailSaysThereIsNoAccountAndOffersToSignUp(): void
    {
        $email = 'nobody-' . uniqid() . '@example.test';
        $page = $this->failLogin($email, 'whatever-password');

        self::assertSame('No hay ninguna cuenta con este email', $page->filter('.error-msg .error-title')->text());
        self::assertSame("Revisa que $email esté bien escrito o crea una cuenta nueva.", $page->filter('.error-msg .error-text')->text());
        self::assertSame('/register?email=' . $email, $page->filter('.error-msg a.error-action')->attr('href'));
        self::assertSame($email, $page->filter('#email')->attr('value'));
        self::assertSame('true', $page->filter('#email')->attr('aria-invalid'));
        self::assertNull($page->filter('#password')->attr('aria-invalid'));
    }

    public function testAWrongPasswordSaysSoAndOffersToResetIt(): void
    {
        $this->user = new User();
        $this->user->setEmail('login-error-' . uniqid() . '@example.test');
        $this->user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, 'the-right-password-1'));
        $this->em->persist($this->user);
        $this->em->flush();

        $page = $this->failLogin($this->user->getEmail(), 'a-wrong-password');

        self::assertSame('La contraseña no es correcta', $page->filter('.error-msg .error-title')->text());
        self::assertSame('/admin/login/recuperar?email=' . $this->user->getEmail(), $page->filter('.error-msg a.error-action')->attr('href'));
        self::assertSame('true', $page->filter('#password')->attr('aria-invalid'));
        self::assertNull($page->filter('#email')->attr('aria-invalid'));
    }

    public function testTheMessagesFollowTheBrowsersLanguage(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en');
        $page = $this->failLogin('nobody-' . uniqid() . '@example.test', 'whatever-password');

        self::assertSame("There's no account with this email", $page->filter('.error-msg .error-title')->text());
        self::assertSame('Create a free account', trim($page->filter('.error-msg a.error-action')->text()));
    }

    public function testTheEmailComesFilledInFromTheSignUp(): void
    {
        $page = $this->client->request('GET', '/admin/login?email=someone%40example.test');

        self::assertSame('someone@example.test', $page->filter('#email')->attr('value'));
        self::assertCount(0, $page->filter('.error-msg'));
    }

    public function testTheLoginFormBypassesTurbo(): void
    {
        $page = $this->client->request('GET', '/admin/login');

        self::assertSame('false', $page->filter('form.login-form')->attr('data-turbo'));
    }

    private function failLogin(string $email, string $password): Crawler
    {
        $page = $this->client->request('GET', '/admin/login');
        $this->client->submit($page->filter('form.login-form')->form(['email' => $email, 'password' => $password]));
        self::assertResponseRedirects('/admin/login');

        return $this->client->followRedirect();
    }
}
