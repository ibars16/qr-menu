<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Self sign-up: "Confirma tu email" (EmailConfirmation — the panel works,
 * the public menu waits) and the optional "Personaliza tu restaurante"
 * step right after (OnboardingController).
 */
final class SignUpConfirmationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?string $email = null;
    /** @var list<string> */
    private array $uploaded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->uploaded as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if ($this->email) {
            $this->em->clear();
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email]);
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

    /** @return string the confirmation link from the welcome email */
    private function register(): string
    {
        $this->email = 'signup-confirm-' . uniqid() . '@example.test';
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Confirmación Test ' . uniqid();
        $form['registration_form[email]'] = $this->email;
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[currency]'] = 'EUR';
        $form['registration_form[language]'] = 'es';
        $form['registration_form[adminLocale]'] = 'es';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/bienvenida');
        self::assertEmailCount(1);
        $html = (string) self::getMailerMessage()->getHtmlBody();
        self::assertStringContainsString('Confirmar mi email', $html);
        self::assertMatchesRegularExpression('#https?://[^"]+/confirmar-email/\d+/[0-9a-f]{16}\?[^"]+#', $html);
        preg_match('#https?://[^"]+(/confirmar-email/\d+/[0-9a-f]{16}\?[^"]+)#', $html, $m);

        return html_entity_decode($m[1]);
    }

    private function user(): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $this->email]);
    }

    private function menuStatusFor(?User $viewer): int
    {
        $slug = $this->user()->getRestaurant()->getSlug();
        $other = static::getContainer()->get('test.client');
        $other->getCookieJar()->clear();
        if ($viewer !== null) {
            $other->loginUser($viewer);
        }
        $other->request('GET', '/r/' . $slug);

        return $other->getResponse()->getStatusCode();
    }

    public function testTheMenuWaitsForTheEmailAndTheLinkPublishesIt(): void
    {
        $link = $this->register();
        $user = $this->user();
        self::assertTrue($user->isEmailConfirmationPending());
        self::assertTrue($user->getRestaurant()->isAwaitingEmailConfirmation());

        // The panel works, with the notice.
        $crawler = $this->client->request('GET', '/admin/bienvenida');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->email, $crawler->filter('.email-confirm-banner')->text());
        // Mobile header: "Ver carta" on every screen.
        self::assertSame('/r/' . $this->user()->getRestaurant()->getSlug(), $crawler->filter('.header-icons a.header-view-menu')->attr('href'));

        self::assertSame(503, $this->menuStatusFor(null), 'customers: hidden');
        self::assertSame(200, $this->menuStatusFor($this->user()), 'the owner still previews it');

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/admin/menu');
        self::assertFalse($this->user()->isEmailConfirmationPending());
        self::assertSame(200, $this->menuStatusFor(null));

        $this->client->request('GET', '/admin/menu');
        self::assertSelectorNotExists('.email-confirm-banner');
    }

    public function testTheLinkWorksLoggedOutAndATamperedOneDoesNot(): void
    {
        $link = $this->register();
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', preg_replace('/_hash=[^&]+/', '_hash=x', $link));
        self::assertResponseStatusCodeSame(400);
        self::assertTrue($this->user()->isEmailConfirmationPending());

        $crawler = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Email confirmado', $crawler->filter('h1')->text());
        self::assertFalse($this->user()->isEmailConfirmationPending());
    }

    public function testALinkForAnOldEmailIsUseless(): void
    {
        $link = $this->register();
        $user = $this->user();
        $user->setEmail($this->email = 'signup-confirm-changed-' . uniqid() . '@example.test');
        $this->em->flush();

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(400);
        self::assertTrue($this->user()->isEmailConfirmationPending());
    }

    public function testResendSendsAnotherLinkOncePerMinute(): void
    {
        $this->register();
        $crawler = $this->client->request('GET', '/admin/menu');
        $form = $crawler->filter('.email-confirm-banner form')->form();

        $this->client->submit($form);
        self::assertEmailCount(1);
        self::assertStringContainsString('/confirmar-email/', (string) self::getMailerMessage()->getHtmlBody());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Te hemos enviado otro enlace', $crawler->filter('.flash-success')->text());

        $this->client->submit($form);
        self::assertEmailCount(0);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Espera un minuto', $crawler->filter('.flash-warning:not(.email-confirm-banner)')->text());
    }

    public function testSmartWaiterIsHiddenToo(): void
    {
        $this->register();
        $slug = $this->user()->getRestaurant()->getSlug();
        $this->client->getCookieJar()->clear();

        $this->client->request('POST', '/r/' . $slug . '/chat', content: json_encode(['message' => 'hola']));
        self::assertResponseStatusCodeSame(503);
    }

    public function testRestaurantsCreatedByOthersNeedNoConfirmation(): void
    {
        $creator = static::getContainer()->get(\App\Service\RestaurantCreator::class);
        $this->email = 'signup-confirm-other-' . uniqid() . '@example.test';
        $user = $creator->create('Otro ' . uniqid(), $this->email, 'a-strong-password-1', 'EUR', 'es', 'es');

        self::assertFalse($user->isEmailConfirmationPending());
        self::assertFalse($user->getRestaurant()->isAwaitingEmailConfirmation());
    }

    public function testTheOptionalStepSavesTaglineLogoAndCover(): void
    {
        $this->register();
        // A real browser page load (Accept: text/html) with the carta still
        // empty: EmptyMenuRedirect must let this step through.
        $crawler = $this->client->request('GET', '/admin/bienvenida', server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.onb-form')->form();
        $form['tagline'] = 'Cocina de mercado';
        $form['logo']->upload($this->copy('upload_png_150x150.png'));
        $form['heroImage']->upload($this->copy('upload_png_500x500.png'));
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/menu');
        $restaurant = $this->user()->getRestaurant();
        self::assertSame('Cocina de mercado', $restaurant->getTagline());
        self::assertNotNull($restaurant->getLogo());
        self::assertNotNull($restaurant->getHeroImage());
        $dir = static::getContainer()->getParameter('app.uploads_dir');
        $this->uploaded = [$dir . '/logos/' . $restaurant->getLogo(), $dir . '/heroes/' . $restaurant->getHeroImage()];
        self::assertFileExists($this->uploaded[0]);
        self::assertFileExists($this->uploaded[1]);
    }

    public function testTheOptionalStepCanBeSkippedAndRejectsALongTagline(): void
    {
        $this->register();
        $crawler = $this->client->request('GET', '/admin/bienvenida');
        self::assertSame('/admin/menu', $crawler->filter('.onb-actions a')->attr('href'), 'Saltar');

        $form = $crawler->filter('form.onb-form')->form();
        $form['tagline'] = str_repeat('a', 161);
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Máximo 160 caracteres', $crawler->filter('#onb-tagline-error')->text());
        self::assertNull($this->user()->getRestaurant()->getTagline());
    }

    private function copy(string $fixture): string
    {
        $path = tempnam(sys_get_temp_dir(), 'onboarding_') . '.png';
        copy(__DIR__ . '/../fixtures/' . $fixture, $path);

        return $path;
    }
}
