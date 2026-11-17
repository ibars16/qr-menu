<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The login's ?_target_path= (after logging in) and ?_failure_path= (after
 * a failed attempt) never send anyone to another website: Symfony's own
 * HttpUtils turns any absolute URL to another host into "/" (it compares
 * it with this request's host, security.yaml has no cookie_domain), and a
 * path starting with "//" stays on this site. Pinned here so a Symfony
 * upgrade or a config change can't silently reopen it.
 */
final class LoginRedirectTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            $this->em->clear();
            $this->em->remove($this->em->find(User::class, $this->userId));
            $this->em->flush();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function otherSites(): iterable
    {
        yield 'https' => ['https://evil.test/phish'];
        yield 'http' => ['http://evil.test'];
        yield 'protocol-relative' => ['//evil.test/phish'];
        yield 'backslashes' => ['https:\\\\evil.test'];
        yield 'look-alike host' => ['http://localhost.evil.test/'];
        yield 'user@host' => ['http://localhost@evil.test/'];
    }

    #[DataProvider('otherSites')]
    public function testAfterLoggingInItStaysOnThisSite(string $target): void
    {
        $email = $this->makeUser();

        $this->login($email, 'the-right-password-1', ['_target_path' => $target]);

        $this->assertStaysOnThisSite();
        $this->assertLoggedIn();
    }

    #[DataProvider('otherSites')]
    public function testTheTargetInTheAddressBarStaysOnThisSite(string $target): void
    {
        $email = $this->makeUser();

        $this->login($email, 'the-right-password-1', [], '?_target_path=' . urlencode($target));

        $this->assertStaysOnThisSite();
        $this->assertLoggedIn();
    }

    #[DataProvider('otherSites')]
    public function testAfterAFailedLoginItStaysOnThisSite(string $target): void
    {
        $this->login('nobody-' . uniqid() . '@example.test', 'whatever-password', ['_failure_path' => $target]);

        $this->assertStaysOnThisSite();
    }

    public function testOwnPathsStillWork(): void
    {
        $email = $this->makeUser();

        $this->login($email, 'the-right-password-1', ['_target_path' => '/admin/qr']);

        self::assertResponseRedirects('http://localhost/admin/qr');
    }

    private function assertStaysOnThisSite(): void
    {
        self::assertTrue($this->client->getResponse()->isRedirect());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $host = parse_url($location, PHP_URL_HOST);

        self::assertTrue($host === null || $host === 'localhost', "redirected to {$location}");
        self::assertStringNotContainsString('evil.test', (string) parse_url($location, PHP_URL_HOST));
    }

    private function assertLoggedIn(): void
    {
        self::assertStringEndsNotWith('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
        // Logged in: the login page itself sends to the panel.
        $this->client->request('GET', '/admin/login');
        self::assertResponseRedirects('/admin/menu');
    }

    /** @param array<string, string> $extra */
    private function login(string $email, string $password, array $extra, string $query = ''): void
    {
        $page = $this->client->request('GET', '/admin/login');
        $token = $page->filter('form.login-form input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/admin/login' . $query, [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => $token,
        ] + $extra);
    }

    private function makeUser(): string
    {
        $user = new User();
        $user->setEmail('login-redirect-' . uniqid() . '@example.test');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'the-right-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $this->em->persist($user);
        $this->em->flush();
        $this->userId = $user->getId();

        return $user->getEmail();
    }
}
