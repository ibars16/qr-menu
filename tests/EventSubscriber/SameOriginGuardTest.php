<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * App\EventSubscriber\SameOriginGuard: a state-changing request to /admin,
 * /partners or /platform from another website is refused before anything
 * runs; from this site — or with no such header at all — it goes through.
 * The rest (public menu, Stripe webhooks) is left alone.
 */
final class SameOriginGuardTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $restaurantId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Same Origin Guard Test');
        $restaurant->setSlug('same-origin-guard-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setRestaurant($restaurant);
        $this->em->persist($category);
        $t = new CategoryTranslation();
        $t->setLocale('es');
        $t->setName('Postres');
        $category->addTranslation($t);
        $this->em->persist($t);

        $user = new User();
        $user->setEmail('same-origin-guard-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->categoryId = $category->getId();
        $this->em->clear();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurantId);
        foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
            $this->em->remove($user);
        }
        foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
            $this->em->remove($category);
        }
        $this->em->remove($restaurant);
        $this->em->flush();

        parent::tearDown();
    }

    /** A real panel action (a JS fetch with no token of its own): names a category in French. */
    private function saveFrench(string $name, array $headers): void
    {
        $this->client->request('POST', '/admin/categories/' . $this->categoryId . '/translations/save', [], [], $headers + ['CONTENT_TYPE' => 'application/json'], json_encode(['translations' => ['fr' => $name]]));
    }

    private function frenchName(): ?string
    {
        $this->em->clear();

        return $this->em->find(Category::class, $this->categoryId)->getTranslation('fr')?->getName();
    }

    private function assertRefusedByTheGuard(): void
    {
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Forbidden', $this->client->getResponse()->getContent());
    }

    private function assertNotRefusedByTheGuard(): void
    {
        self::assertNotSame('Forbidden', $this->client->getResponse()->getContent());
    }

    public static function anotherSite(): iterable
    {
        yield 'Sec-Fetch-Site: cross-site' => [['HTTP_SEC_FETCH_SITE' => 'cross-site']];
        yield 'Sec-Fetch-Site: same-site' => [['HTTP_SEC_FETCH_SITE' => 'same-site']];
        yield 'Origin of another site' => [['HTTP_ORIGIN' => 'https://evil.test']];
        yield 'Origin that only starts like ours' => [['HTTP_ORIGIN' => 'http://localhost.evil.test']];
        yield 'Referer of another site' => [['HTTP_REFERER' => 'https://evil.test/page']];
        yield 'Origin: null' => [['HTTP_ORIGIN' => 'null']];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('anotherSite')]
    public function testFromAnotherSiteNothingChanges(array $headers): void
    {
        $this->saveFrench('Desserts', $headers);

        $this->assertRefusedByTheGuard();
        self::assertNull($this->frenchName());
    }

    public static function thisSite(): iterable
    {
        yield 'Sec-Fetch-Site: same-origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin']];
        yield 'Sec-Fetch-Site: none' => [['HTTP_SEC_FETCH_SITE' => 'none']];
        yield 'our Origin' => [['HTTP_ORIGIN' => 'http://localhost']];
        yield 'our Referer' => [['HTTP_REFERER' => 'http://localhost/admin/menu']];
        yield 'no header at all' => [[]];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('thisSite')]
    public function testFromThisSiteItWorks(array $headers): void
    {
        $this->saveFrench('Desserts', $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('Desserts', $this->frenchName());
    }

    public function testSecFetchSiteWinsOverOrigin(): void
    {
        $this->saveFrench('Desserts', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'http://localhost']);

        $this->assertRefusedByTheGuard();
    }

    public function testReadingFromAnotherSiteIsLeftAlone(): void
    {
        $this->client->request('GET', '/admin/menu', server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseIsSuccessful();
    }

    public static function panels(): iterable
    {
        yield 'PUT /admin' => ['PUT', '/admin/anything'];
        yield 'PATCH /admin' => ['PATCH', '/admin/anything'];
        yield 'DELETE /admin' => ['DELETE', '/admin/anything'];
        yield 'POST /partners' => ['POST', '/partners/restaurantes/1/archivar'];
        yield 'POST /platform' => ['POST', '/platform/restaurants/1/deactivate'];
    }

    #[DataProvider('panels')]
    public function testEveryPanelAndMethodIsGuarded(string $method, string $path): void
    {
        $this->client->request($method, $path, server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->assertRefusedByTheGuard();
    }

    public static function outsideThePanels(): iterable
    {
        yield 'Stripe webhook' => ['/stripe/webhook'];
        yield 'Stripe Connect webhook' => ['/stripe/connect-webhook'];
        yield 'public menu' => ['/r/no-such-restaurant/view'];
        yield 'loading screen' => ['/r/no-such-restaurant/warm-translations?lang=fr'];
        yield 'look-alike path' => ['/administrator'];
    }

    #[DataProvider('outsideThePanels')]
    public function testOutsideThePanelsIsLeftAlone(string $path): void
    {
        $this->client->request('POST', $path, server: ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://stripe.test']);

        $this->assertNotRefusedByTheGuard();
    }
}
