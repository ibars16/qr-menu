<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * An expired restaurant (Restaurant::isExpired()): the public menu is a
 * plain 503 page with the name and a translated message, and every endpoint
 * that would spend AI or write data (Smart Waiter, warm-translations, view
 * tracking) is cut off before doing any work.
 */
final class MenuExpiredRestaurantTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Expired Menu Test Restaurant');
        $this->restaurant->setSlug('expired-menu-test-' . uniqid());
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->deactivateNow();
        $this->em->persist($this->restaurant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function url(string $suffix = ''): string
    {
        return '/r/' . $this->restaurant->getSlug() . $suffix;
    }

    public function testMenuIsUnavailableWith503(): void
    {
        $this->client->request('GET', $this->url() . '?lang=es');

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextSame('h1', 'Expired Menu Test Restaurant');
        self::assertSelectorTextSame('.text', 'Carta no disponible en este momento');
    }

    public function testMessageFollowsRequestedLanguage(): void
    {
        $this->client->request('GET', $this->url() . '?lang=ar');

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextSame('.text', 'القائمة غير متاحة حاليًا');
        self::assertSelectorExists('html[dir="rtl"]');
    }

    public function testActiveRestaurantStillServesTheMenu(): void
    {
        $this->restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        $this->em->flush();

        $this->client->request('GET', $this->url() . '?lang=es');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.text:contains("Carta no disponible")');
    }

    public function testSmartWaiterIsCutOff(): void
    {
        $this->client->request('POST', $this->url('/chat'), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['message' => 'hola', 'locale' => 'es']));

        self::assertResponseStatusCodeSame(503);
    }

    public function testWarmTranslationsIsCutOff(): void
    {
        $this->client->request('POST', $this->url('/warm-translations') . '?lang=fr');

        self::assertResponseStatusCodeSame(503);
    }

    public function testViewTrackingIsCutOff(): void
    {
        $this->client->request('POST', $this->url('/view'), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['productId' => 1]));

        self::assertResponseStatusCodeSame(503);
    }

    /** @return iterable<string, array{string}> */
    public static function publicLocales(): iterable
    {
        foreach (array_keys(require dirname(__DIR__, 2) . '/config/languages.php') as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('publicLocales')]
    public function testUnavailableMessageExistsInEveryPublicLanguage(string $locale): void
    {
        $catalogue = Yaml::parseFile(dirname(__DIR__, 2) . '/translations/menu_public.' . $locale . '.yaml');

        self::assertNotEmpty($catalogue['unavailable']['message'] ?? null);
    }
}
