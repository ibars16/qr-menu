<?php

namespace App\Tests\Controller;

use App\Service\PublicSiteLocaleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * About and the legal pages: in the public site's language (like the
 * home), with its language selector, and the picked language carried over
 * between pages (PublicSiteLocaleResolver::COOKIE).
 */
final class PagesControllerTest extends WebTestCase
{
    private const PAGES = [
        'about'   => '/sobre-nosotros',
        'privacy' => '/privacidad',
        'terms'   => '/terminos',
        'cookies' => '/cookies',
    ];

    /** A heading of each page's text, per language: proof the body itself is translated. */
    private const FIRST_H2 = [
        'about'   => ['es' => 'Qué hacemos', 'en' => 'What we do', 'fr' => 'Ce que nous faisons', 'de' => 'Was wir machen', 'it' => 'Cosa facciamo', 'pt' => 'O que fazemos'],
        'privacy' => ['es' => '1. Responsable del tratamiento', 'en' => '1. Data controller', 'fr' => '1. Responsable du traitement', 'de' => '1. Verantwortlicher', 'it' => '1. Titolare del trattamento', 'pt' => '1. Responsável pelo tratamento'],
        'terms'   => ['es' => '1. Aceptación de estos términos', 'en' => '1. Acceptance of these terms', 'fr' => '1. Acceptation des présentes conditions', 'de' => '1. Annahme dieser Bedingungen', 'it' => '1. Accettazione dei presenti termini', 'pt' => '1. Aceitação destes termos'],
        'cookies' => ['es' => 'Qué usamos', 'en' => 'What we use', 'fr' => 'Ce que nous utilisons', 'de' => 'Was wir verwenden', 'it' => 'Cosa utilizziamo', 'pt' => 'O que utilizamos'],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    /** @return iterable<string, array{string, string}> */
    public static function pagesAndLocales(): iterable
    {
        foreach (array_keys(self::PAGES) as $page) {
            foreach (['es', 'en', 'fr', 'de', 'it', 'pt'] as $locale) {
                yield $page . '-' . $locale => [$page, $locale];
            }
        }
    }

    #[DataProvider('pagesAndLocales')]
    public function testPageIsInTheChosenLanguageWithTheSelector(string $page, string $locale): void
    {
        $path = self::PAGES[$page];
        $crawler = $this->client->request('GET', $path . '?lang=' . $locale, server: ['HTTP_ACCEPT_LANGUAGE' => '']);

        self::assertResponseIsSuccessful();
        self::assertSame($locale, $crawler->filter('html')->attr('lang'));
        $translator = static::getContainer()->get('translator');
        self::assertSame($translator->trans('pages.' . $page . '.title', [], 'public_site', $locale), $crawler->filter('.legal h1')->text());
        self::assertSame(self::FIRST_H2[$page][$locale], $crawler->filter('.legal h2')->first()->text());
        self::assertSame($translator->trans('footer.about', [], 'public_site', $locale), $crawler->filter('.footer a[href="/sobre-nosotros"]')->text());

        // The selector (one, for desktop and mobile): this same page in each language.
        $options = $crawler->filter('.nav-lang-menu a.nav-lang-item');
        self::assertCount(6, $options);
        self::assertSame($path . '?lang=fr', $crawler->filter('.nav-lang-menu a[hreflang="fr"]')->attr('href'));
        self::assertStringContainsString(' active', $crawler->filter('.nav-lang-menu a[hreflang="' . $locale . '"]')->attr('class'));
        // A missing key would show up as the key itself.
        self::assertDoesNotMatchRegularExpression('/\b(pages|nav|footer)\.[a-z_]+/', $crawler->filter('body')->text());
    }

    public function testNavControlsAreTranslated(): void
    {
        $crawler = $this->client->request('GET', '/terminos?lang=en');

        self::assertSame('Switch between dark and light mode', $crawler->filter('.nav-theme-toggle')->first()->attr('aria-label'));
        self::assertSame('Open menu', $crawler->filter('.nav-mobile-toggle')->attr('aria-label'));
        self::assertSame('Language', $crawler->filter('.nav-lang-current')->attr('aria-label'));
        self::assertSame('Dark mode', $crawler->filter('.nav-mobile-theme span.theme-icon-light')->text());
    }

    public function testChosenLanguageIsRememberedAcrossPages(): void
    {
        $this->client->request('GET', '/?lang=de', server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $cookie = $this->client->getCookieJar()->get(PublicSiteLocaleResolver::COOKIE);
        self::assertNotNull($cookie);
        self::assertSame('de', $cookie->getValue());

        $crawler = $this->client->request('GET', '/privacidad', server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        self::assertSame('Datenschutzerklärung', $crawler->filter('.legal h1')->text());

        $crawler = $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        self::assertSame('de', $crawler->filter('html')->attr('lang'));
    }

    public function testLangParameterWinsOverTheRememberedLanguageAndReplacesIt(): void
    {
        $this->client->getCookieJar()->set(new Cookie(PublicSiteLocaleResolver::COOKIE, 'de', null, '/', 'localhost'));

        $crawler = $this->client->request('GET', '/cookies?lang=it');
        self::assertSame('Cookie Policy', $crawler->filter('.legal h1')->text());
        self::assertSame('it', $this->client->getCookieJar()->get(PublicSiteLocaleResolver::COOKIE, '/', 'localhost')->getValue());
    }

    public function testUnsupportedRememberedLanguageFallsBackToTheBrowser(): void
    {
        $this->client->getCookieJar()->set(new Cookie(PublicSiteLocaleResolver::COOKIE, 'zz', null, '/', 'localhost'));

        $crawler = $this->client->request('GET', '/sobre-nosotros', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);
        self::assertSame('À propos', $crawler->filter('.legal h1')->text());
    }

    public function testNoCookieWithoutAChoice(): void
    {
        $this->client->request('GET', '/terminos', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertNull($this->client->getCookieJar()->get(PublicSiteLocaleResolver::COOKIE));
        self::assertSame('en', $this->client->getCrawler()->filter('html')->attr('lang'));
    }

    public function testTheCookiePolicyListsTheLanguageCookieInEveryLanguage(): void
    {
        foreach (['es', 'en', 'fr', 'de', 'it', 'pt'] as $locale) {
            $crawler = $this->client->request('GET', '/cookies?lang=' . $locale);
            self::assertStringContainsString(PublicSiteLocaleResolver::COOKIE, $crawler->filter('.legal')->text(), $locale);
        }
    }

    #[DataProvider('staticPages')]
    public function testStaticPageIsReachable(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string}> */
    public static function staticPages(): iterable
    {
        foreach (self::PAGES as $page => $path) {
            yield $page => [$path];
        }
    }

    public function testHomeFootersLinkToEveryStaticPage(): void
    {
        $this->client->request('GET', '/');

        $content = $this->client->getResponse()->getContent();
        foreach (self::PAGES as $path) {
            self::assertStringContainsString('href="' . $path . '"', $content);
        }
    }

    public function testEveryPageHasItsTextInEveryLanguage(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/templates/pages';
        $locales = static::getContainer()->get(PublicSiteLocaleResolver::class)->getSupportedLocaleCodes();
        foreach (array_keys(self::PAGES) as $page) {
            foreach ($locales as $locale) {
                self::assertFileExists($dir . '/' . $page . '/' . $locale . '.html.twig');
            }
        }
    }
}
