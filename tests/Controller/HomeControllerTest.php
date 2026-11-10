<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The pricing section and the "¿Cuánto cuesta?" answer show
     * app.billing_prices (cents) — the same source as the admin's plan
     * dialog — not amounts written in the template or translations.
     */
    public function testPricingShowsThePricesFromTheConfig(): void
    {
        $prices = static::getContainer()->getParameter('app.billing_prices');
        self::assertSame(['monthly' => 3999, 'yearly' => 39999], $prices);

        $page = $this->client->request('GET', '/?lang=es');
        self::assertResponseIsSuccessful();

        $pricing = $page->filter('section#precios');
        self::assertCount(1, $pricing);
        self::assertSame('39,99 €', $pricing->filter('[data-price="monthly"]')->text());
        self::assertSame('399,99 €', $pricing->filter('[data-price="yearly"]')->text());
        self::assertSame('Ahorras 79,89 € frente al pago mensual', $pricing->filter('[data-saving]')->text());
        self::assertCount(2, $pricing->filter('.pricing-vat'));
        self::assertStringContainsString('IVA incluido', $pricing->text());
        self::assertStringNotContainsString('meses gratis', $page->text());
        self::assertSame('/register', $pricing->filter('a.pricing-cta')->attr('href'));
        self::assertSame('Sin tarjeta de crédito', $pricing->filter('.pricing-note')->text());

        // No JS: the switch is hidden and both periods are in the page; with
        // JS, "Mensual" comes first and starts selected.
        self::assertNotNull($pricing->filter('[data-pricing-toggle]')->attr('hidden'));
        self::assertCount(2, $pricing->filter('[data-option]'));
        self::assertSame(['Mensual', 'Anual'], $pricing->filter('[data-pricing-toggle] button')->each(static fn ($b): string => $b->text()));
        self::assertSame('true', $pricing->filter('button[data-period="monthly"]')->attr('aria-pressed'));
        self::assertSame('false', $pricing->filter('button[data-period="yearly"]')->attr('aria-pressed'));
        self::assertSame('monthly', $pricing->filter('.pricing-option.is-active')->attr('data-option'));
        self::assertStringContainsString("show('monthly')", $this->client->getResponse()->getContent());

        self::assertSame('¿Cuánto cuesta?', trim($page->filter('.faq-item summary')->first()->text()));
        self::assertStringStartsWith('39,99 € al mes o 399,99 € al año, IVA incluido.', $page->filter('.faq-item p')->first()->text());
    }

    public function testPricingIsFormattedForEnglish(): void
    {
        $pricing = $this->client->request('GET', '/?lang=en')->filter('section#precios');

        self::assertSame('€39.99', $pricing->filter('[data-price="monthly"]')->text());
        self::assertSame('€399.99', $pricing->filter('[data-price="yearly"]')->text());
        self::assertSame('You save €79.89 compared to paying monthly', $pricing->filter('[data-saving]')->text());
    }

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (['de', 'en', 'es', 'fr', 'it', 'pt'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryLocaleHasTheNewSectionsTranslated(string $locale): void
    {
        $page = $this->client->request('GET', '/?lang=' . $locale);
        self::assertResponseIsSuccessful();

        self::assertCount(8, $page->filter('#funcionalidades .feature-card'));
        self::assertCount(6, $page->filter('#precios .pricing-list li'));
        self::assertCount(6, $page->filter('.faq-item'));
        // A missing key would show up as the key itself.
        self::assertDoesNotMatchRegularExpression('/\b(pricing|features|faq|nav|footer)\.[a-z_0-9.]+\b/', $page->filter('body')->text());
        self::assertStringNotContainsString('20+', $page->text());
        self::assertSame('#precios', $page->filter('.hero-ctas a.btn-secondary')->attr('href'));
        self::assertCount(1, $page->filter('.footer a[href="#precios"]'));
    }

    public function testTheFeatureCardsNoLongerListAdminPanelOrSetMenus(): void
    {
        $page = $this->client->request('GET', '/?lang=es');
        $titles = $page->filter('#funcionalidades .feature-card h3')->each(static fn ($h): string => $h->text());

        self::assertSame([
            'Carta digital por QR', 'Importa tu carta con una foto', 'Camarero con IA', '14 alérgenos oficiales',
            '20 idiomas', 'Multidivisas', 'Estadísticas', 'Temas visuales',
        ], $titles);
        self::assertStringNotContainsString('más de 20', $page->text());
        self::assertStringContainsString('Tu carta se pausa hasta que elijas un plan.', $page->filter('.faq')->text());
    }

    public function testOtherPublicPagesLinkToTheHomePricing(): void
    {
        $page = $this->client->request('GET', '/sobre-nosotros');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $page->filter('.footer a[href="/#precios"]'));
    }

    public function testLoggedInOwnerSeesTheLandingPageWithALinkToThePanel(): void
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
        $page = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $page->filter('nav.nav a[href="/admin/menu"]'));   // desktop + mobile
        self::assertCount(1, $page->filter('.footer a[href="/admin/menu"]'));
        self::assertCount(0, $page->filter('a[href="/admin/login"]'));
        self::assertCount(0, $page->filter('nav.nav a[href="/register"]'));

        $this->em->remove($owner);
        $this->em->remove($restaurant);
        $this->em->flush();
    }
}
