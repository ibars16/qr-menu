<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Service\CategoryTranslatorInterface;
use App\Service\ProductTranslatorInterface;
use App\Service\TagTranslatorInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The customer's language/currency window (menu/_prefs_dialog.html.twig) is
 * in the menu's language — its title, both labels and the button — not in
 * the app's default English: they used to be translated without the menu's
 * locale. An empty Spanish menu, so there's nothing to translate and no
 * loading screen; no AI involved (a call would fail the test).
 */
final class MenuPrefsDialogLanguageTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $restaurantId;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        $this->client->disableReboot();

        $noAi = new class implements ProductTranslatorInterface, CategoryTranslatorInterface, TagTranslatorInterface {
            public function translate(array $items, string $targetLocale, array $sourceLocales = []): array
            {
                throw new \LogicException('No AI call expected while rendering the menu.');
            }
        };
        $container = static::getContainer();
        $container->set(ProductTranslatorInterface::class, $noAi);
        $container->set(CategoryTranslatorInterface::class, $noAi);
        $container->set(TagTranslatorInterface::class, $noAi);

        $em = $container->get(EntityManagerInterface::class);
        $restaurant = new Restaurant();
        $restaurant->setName('Prefs Dialog Language Test');
        $this->slug = 'prefs-dialog-language-test-' . uniqid();
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $em->persist($restaurant);
        $em->flush();
        $this->restaurantId = $restaurant->getId();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->executeStatement('DELETE FROM restaurant WHERE id = ?', [$this->restaurantId]);

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return ['es' => ['es'], 'de' => ['de'], 'ja' => ['ja'], 'ar' => ['ar']];
    }

    /** @return array{title: string, language_label: string, currency_label: string, confirm: string} */
    private static function dialogTexts(string $locale): array
    {
        return Yaml::parseFile(dirname(__DIR__, 2) . "/translations/menu_public.{$locale}.yaml")['dialog'];
    }

    #[DataProvider('locales')]
    public function testTheWindowIsInTheMenusLanguage(string $locale): void
    {
        $crawler = $this->client->request('GET', "/r/{$this->slug}?lang={$locale}");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#mp-overlay'), 'the menu itself, not the loading screen');

        $expected = self::dialogTexts($locale);
        $shown = [
            'title'          => trim($crawler->filter('#mp-title')->text()),
            'language_label' => trim($crawler->filter('label[for="mp-lang"]')->text()),
            'currency_label' => trim($crawler->filter('label[for="mp-currency"]')->text()),
            'confirm'        => trim($crawler->filter('#mp-confirm')->text()),
        ];
        self::assertSame($expected, $shown);

        foreach (self::dialogTexts('en') as $key => $english) {
            self::assertNotSame($english, $shown[$key], "{$key} still in English");
        }
    }
}
