<?php

namespace App\Tests\Controller;

use App\Entity\ResetPasswordRequest;
use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Forgot your password?" from /admin/login (ResetPasswordController):
 * always the same answer, a link that lasts 1 hour and works once, a new
 * link (or a new password) kills the older ones, 3 requests per email and
 * 10 per IP per hour, the email in the user's panel language.
 */
final class ResetPasswordTest extends WebTestCase
{
    private const SENT = 'Si ese email tiene cuenta, te hemos enviado un enlace.';
    private const INVALID = 'El enlace no es válido o ha caducado. Pide uno nuevo.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // The IP allowance lives in the cache, across tests and runs.
        static::getContainer()->get('limiter.reset_password_ip')->create('127.0.0.1')->reset();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'reset-test-%\'');
        $conn->executeStatement("DELETE FROM restaurant WHERE slug LIKE 'reset-test-%'");
        $conn->executeStatement("DELETE FROM reseller WHERE name LIKE 'Reset Test%'");
        static::getContainer()->get('limiter.reset_password_ip')->create('127.0.0.1')->reset();

        parent::tearDown();
    }

    private function user(?string $restaurantLocale = 'es', ?string $resellerLocale = null): User
    {
        $user = new User();
        $user->setEmail('reset-test-' . uniqid() . '@example.test');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'the-old-password'));

        if ($resellerLocale !== null) {
            $reseller = new Reseller('Reset Test Agency');
            $reseller->setAdminLocale($resellerLocale);
            $this->em->persist($reseller);
            $user->setRoles([User::ROLE_RESELLER]);
            $user->setReseller($reseller);
        } elseif ($restaurantLocale !== null) {
            $restaurant = new Restaurant();
            $restaurant->setName('Reset Test Restaurant');
            $restaurant->setSlug('reset-test-' . uniqid());
            $restaurant->setAdminLocale($restaurantLocale);
            $this->em->persist($restaurant);
            $user->setRoles([User::ROLE_OWNER]);
            $user->setRestaurant($restaurant);
        } else {
            $user->setRoles([User::ROLE_SUPER_ADMIN]);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Submits "forgot password" and returns the emails it sent (before following the redirect). @return Email[] */
    private function ask(string $email): array
    {
        $crawler = $this->client->request('GET', '/admin/login/recuperar');
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['form[email]'] = $email;
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/login/recuperar');
        $sent = self::sent();

        $page = $this->client->followRedirect();
        self::assertSame(self::SENT, $page->filter('.notice-ok')->text(), 'always the very same answer');

        return $sent;
    }

    /** Emails actually sent by the last request (the mailer also logs each one as "queued" first). @return Email[] */
    private static function sent(): array
    {
        $events = array_filter(self::getMailerEvents(), static fn ($e) => !$e->isQueued());

        return array_values(array_map(static fn ($e) => $e->getMessage(), $events));
    }

    private function linkIn(Email $email): string
    {
        self::assertSame(1, preg_match('#href="(http://localhost/admin/login/recuperar/[A-Za-z0-9]{40})"#', $email->getHtmlBody(), $m));

        return $m[1];
    }

    /** Opens the link and submits the new password; returns the final page's path. */
    private function useLink(string $link, string $password = 'a-brand-new-password'): void
    {
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/admin/login/recuperar/nueva', message: 'the token leaves the URL straight away');
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['form[password][first]'] = $password;
        $form['form[password][second]'] = $password;
        $this->client->submit($form);
    }

    private function passwordIs(User $user, string $password): bool
    {
        $this->em->clear();
        $fresh = $this->em->find(User::class, $user->getId());

        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($fresh, $password);
    }

    public function testSameAnswerWithAndWithoutAnAccount(): void
    {
        $user = $this->user();

        $withAccount = $this->ask($user->getEmail());
        $without = $this->ask('reset-test-nobody-' . uniqid() . '@example.test');

        self::assertCount(1, $withAccount);
        self::assertSame($user->getEmail(), $withAccount[0]->getTo()[0]->getAddress());
        self::assertSame('Cambia tu contraseña', $withAccount[0]->getSubject());
        self::assertCount(0, $without);
    }

    public function testTheLinkChangesThePasswordOnceAndBackToLoginWithTheMessage(): void
    {
        $user = $this->user();
        $link = $this->linkIn($this->ask(strtoupper($user->getEmail()))[0]); // email case doesn't matter

        $this->useLink($link);

        self::assertResponseRedirects('/admin/login');
        self::assertStringContainsString('Contraseña cambiada. Ya puedes entrar.', $this->client->followRedirect()->filter('.success-msg')->text());
        self::assertTrue($this->passwordIs($user, 'a-brand-new-password'));

        // Second use: refused, password untouched.
        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/login/recuperar');
        self::assertSame(self::INVALID, $this->client->followRedirect()->filter('.notice-error')->text());
        self::assertTrue($this->passwordIs($user, 'a-brand-new-password'));
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $user = $this->user();
        $link = $this->linkIn($this->ask($user->getEmail())[0]);

        $this->em->getConnection()->executeStatement(
            "UPDATE reset_password_request SET expires_at = NOW() - INTERVAL '1 minute' WHERE user_id = ?",
            [$user->getId()]
        );

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/login/recuperar');
        self::assertSame(self::INVALID, $this->client->followRedirect()->filter('.notice-error')->text());
        self::assertTrue($this->passwordIs($user, 'the-old-password'));
    }

    public function testTheLinkLastsOneHour(): void
    {
        $user = $this->user();
        $this->ask($user->getEmail());

        $this->em->clear();
        $request = $this->em->getRepository(ResetPasswordRequest::class)->findOneBy(['user' => $user->getId()]);
        $minutes = ($request->getExpiresAt()->getTimestamp() - $request->getRequestedAt()->getTimestamp()) / 60;
        self::assertSame(60, (int) round($minutes));
    }

    public function testANewLinkKillsTheOlderOne(): void
    {
        $user = $this->user();
        $first = $this->linkIn($this->ask($user->getEmail())[0]);
        $second = $this->linkIn($this->ask($user->getEmail())[0]);

        $this->client->request('GET', $first);
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/login/recuperar', message: 'the first link no longer works');

        $this->useLink($second);
        self::assertResponseRedirects('/admin/login');
        self::assertTrue($this->passwordIs($user, 'a-brand-new-password'));
    }

    public function testNewPasswordFollowsTheSignUpRules(): void
    {
        $user = $this->user();
        $link = $this->linkIn($this->ask($user->getEmail())[0]);

        $this->useLink($link, 'short');
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('.errors li', 'Mínimo 8 caracteres.');

        $form = $this->client->getCrawler()->filter('form:not(.lang-form)')->form();
        $form['form[password][first]'] = 'a-brand-new-password';
        $form['form[password][second]'] = 'something-else-entirely';
        $this->client->submit($form);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('.errors li', 'Las contraseñas no coinciden.');

        self::assertTrue($this->passwordIs($user, 'the-old-password'));
    }

    public function testThreeRequestsPerEmailAndHour(): void
    {
        $user = $this->user();

        for ($i = 1; $i <= 3; ++$i) {
            self::assertCount(1, $this->ask($user->getEmail()), "request #$i is sent");
        }
        self::assertCount(0, $this->ask($user->getEmail()), 'the 4th gets the same answer and nothing is sent');
    }

    public function testTenRequestsPerIpAndHour(): void
    {
        $user = $this->user();

        for ($i = 1; $i <= 10; ++$i) {
            $this->ask('reset-test-other-' . uniqid() . '@example.test');
        }
        self::assertCount(0, $this->ask($user->getEmail()), 'the 11th from this IP sends nothing, same answer');
    }

    public function testTheEmailIsInThePanelLanguageNotTheBrowsers(): void
    {
        $owner = $this->user(restaurantLocale: 'de');
        $reseller = $this->user(resellerLocale: 'fr');
        $platformOwner = $this->user(restaurantLocale: null);

        self::assertSame('Ändern Sie Ihr Passwort', $this->ask($owner->getEmail())[0]->getSubject());
        self::assertSame('Changez votre mot de passe', $this->ask($reseller->getEmail())[0]->getSubject());

        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en');
        $crawler = $this->client->request('GET', '/admin/login/recuperar');
        self::assertSame('Forgot your password?', $crawler->filter('h1')->text(), 'the screen follows the browser');
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['form[email]'] = $platformOwner->getEmail();
        $this->client->submit($form);
        self::assertSame('Cambia tu contraseña', self::sent()[0]->getSubject(), '/platform is Spanish only');
    }

    public function testChangingAStaffPasswordFromTheTeamScreenKillsTheirLink(): void
    {
        $staff = $this->user();
        $link = $this->linkIn($this->ask($staff->getEmail())[0]);

        $owner = new User();
        $owner->setEmail('reset-test-owner-' . uniqid() . '@example.test');
        $owner->setPassword('unused');
        $owner->setRoles([User::ROLE_OWNER]);
        $this->em->clear();
        $owner->setRestaurant($this->em->find(User::class, $staff->getId())->getRestaurant());
        $this->em->persist($owner);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('POST', '/admin/usuarios/' . $staff->getId() . '/editar', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['role' => 'staff', 'password' => 'set-by-the-owner']));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/logout');

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/login/recuperar');
    }
}
