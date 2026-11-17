<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Security\SignupLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Sign-ups per IP (App\Security\SignupLimiter): 3 an hour and 10 a day,
 * one count for /register, /alta/{code} and /partners/registro. Past it
 * the form shows why and no account is created. The counts live in memory
 * for this test's kernel only (rate_limiter.yaml, when@test), hence
 * disableReboot; the IP is the test client's 127.0.0.1.
 */
final class SignupLimitTest extends WebTestCase
{
    private const IP = '127.0.0.1';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $emails = [];
    private ?int $resellerId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->emails as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $restaurant = $user->getRestaurant();
                $reseller = $user->getReseller();
                $this->em->remove($user);
                $this->em->flush();
                if ($restaurant) {
                    $this->em->remove($restaurant);
                }
                if ($reseller && $reseller->getId() !== $this->resellerId) {
                    $this->em->remove($reseller);
                }
                $this->em->flush();
            }
        }
        if ($this->resellerId !== null && ($reseller = $this->em->find(Reseller::class, $this->resellerId))) {
            $this->em->remove($reseller);
            $this->em->flush();
        }

        parent::tearDown();
    }

    private function limiter(string $name): RateLimiterFactoryInterface
    {
        return static::getContainer()->get('limiter.' . $name);
    }

    private function useUp(string $name, int $count): void
    {
        self::assertTrue($this->limiter($name)->create(self::IP)->consume($count)->isAccepted());
    }

    private function remaining(string $name): int
    {
        return $this->limiter($name)->create(self::IP)->consume(0)->getRemainingTokens();
    }

    private function newEmail(): string
    {
        return $this->emails[] = 'signup-limit-' . uniqid() . '@example.test';
    }

    private function registerRestaurant(string $url = '/register'): string
    {
        $email = $this->newEmail();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Signup Limit Restaurant';
        $form['registration_form[email]'] = $email;
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[acceptTerms]']->tick();
        $form['registration_form[currency]'] = 'EUR';
        $form['registration_form[language]'] = 'es';
        $form['registration_form[adminLocale]'] = 'es';
        $this->client->submit($form);

        return $email;
    }

    private function registerReseller(): string
    {
        $email = $this->newEmail();
        $crawler = $this->client->request('GET', '/partners/registro');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['reseller_registration_form[company]'] = 'Signup Limit Agency';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = $email;
        $form['reseller_registration_form[password][first]'] = 'a-strong-password-1';
        $form['reseller_registration_form[password][second]'] = 'a-strong-password-1';
        $form['reseller_registration_form[acceptTerms]']->tick();
        $form['reseller_registration_form[adminLocale]'] = 'es';
        $this->client->submit($form);

        return $email;
    }

    private function assertRefused(string $email): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'Se han creado demasiadas cuentas desde esta conexión. Inténtalo de nuevo más tarde.');
        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]), 'no account created');
    }

    public function testACreatedAccountCountsAndTheFourthInAnHourIsRefused(): void
    {
        $this->registerRestaurant();
        self::assertResponseRedirects('/admin/bienvenida');
        self::assertSame(2, $this->remaining('signup_ip_hour'));
        self::assertSame(9, $this->remaining('signup_ip_day'));

        // Logged in now: back out, as another sign-up from the same connection.
        $this->client->getContainer()->get('security.token_storage')->setToken(null);
        $this->client->getCookieJar()->clear();
        $this->useUp('signup_ip_hour', 2);

        $this->assertRefused($this->registerRestaurant());
        self::assertSame(9, $this->remaining('signup_ip_day'), 'a refused one counts nothing');
    }

    public function testTenADay(): void
    {
        $this->useUp('signup_ip_day', 10);

        $this->assertRefused($this->registerRestaurant());
    }

    public function testAFormWithErrorsCountsNothing(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Signup Limit Restaurant';
        $form['registration_form[email]'] = 'not-an-email';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(3, $this->remaining('signup_ip_hour'));
    }

    public function testTheResellersSignUpLinkSharesTheCount(): void
    {
        $reseller = new Reseller('Signup Limit Reseller');
        $reseller->markPaid(Restaurant::PLAN_MONTHLY);
        $this->em->persist($reseller);
        $this->em->flush();
        $this->resellerId = $reseller->getId();

        $this->useUp('signup_ip_hour', 3);

        $this->assertRefused($this->registerRestaurant('/alta/' . $reseller->getSignupCode()));
    }

    public function testTheResellersOwnSignUpSharesTheCount(): void
    {
        $this->useUp('signup_ip_hour', 3);

        $this->assertRefused($this->registerReseller());
    }

    public function testOffWhenTheAbuseLimitsAreOff(): void
    {
        $this->useUp('signup_ip_hour', 3);
        $request = Request::create('/register', server: ['REMOTE_ADDR' => self::IP]);

        $on = new SignupLimiter($this->limiter('signup_ip_hour'), $this->limiter('signup_ip_day'), true);
        $off = new SignupLimiter($this->limiter('signup_ip_hour'), $this->limiter('signup_ip_day'), false);

        self::assertFalse($on->allows($request));
        self::assertTrue($off->allows($request), 'dev: never refused');
        $off->count($request);
        self::assertSame(10, $this->remaining('signup_ip_day'), 'and counts nothing');
    }
}
