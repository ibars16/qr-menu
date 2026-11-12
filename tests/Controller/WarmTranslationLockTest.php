<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Service\CategoryTranslatorInterface;
use App\Service\ProductTranslatorInterface;
use App\Service\TagTranslatorInterface;
use App\Service\TranslationDeadline;
use App\Service\WarmTranslationLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * The loading screen's translation (MenuController::warmTranslations())
 * braked: one visit at a time per restaurant + language (WarmTranslationLock,
 * a PostgreSQL advisory lock — "another visit" here holds it from its own
 * DB connection) and 20 every 10 minutes per IP (menu_warm_ip). Fake AI,
 * fake clock: no real call, no real wait.
 */
final class WarmTranslationLockTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    /** Another visit's own DB session. */
    private Connection $otherVisit;
    private object $clock;
    private object $dishAi;
    private object $categoryAi;
    private string $slug;
    private ?int $restaurantId = null;
    private int $categoryId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);
        $this->otherVisit = DriverManager::getConnection($this->conn->getParams());

        // A fake clock that can run "another visit" while the request waits.
        $this->clock = new class implements ClockInterface {
            public MockClock $inner;
            /** Runs on each sleep() (each poll of the lock), with the number of polls so far. */
            public ?\Closure $onSleep = null;
            public int $sleeps = 0;

            public function __construct()
            {
                $this->inner = new MockClock();
            }

            public function now(): \DateTimeImmutable
            {
                return $this->inner->now();
            }

            public function sleep(float|int $seconds): void
            {
                $this->inner->sleep($seconds);
                $this->sleeps++;
                if ($this->onSleep !== null) {
                    ($this->onSleep)($this->sleeps);
                }
            }

            public function withTimeZone(\DateTimeZone|string $timezone): static
            {
                return $this;
            }
        };
        static::getContainer()->set('clock', $this->clock);

        $this->dishAi = new class implements ProductTranslatorInterface {
            public int $calls = 0;
            public bool $fail = false;

            public function translate(array $products, string $targetLocale, array $sourceLocales = []): array
            {
                $this->calls++;
                if ($this->fail) {
                    throw new \RuntimeException('fake AI down');
                }

                return array_map(static fn (array $p) => ['name' => "[{$targetLocale}] " . $p['name'], 'description' => null], $products);
            }
        };
        $this->categoryAi = new class implements CategoryTranslatorInterface, TagTranslatorInterface {
            public int $calls = 0;

            public function translate(array $names, string $targetLocale, array $sourceLocales = []): array
            {
                $this->calls++;

                return array_map(static fn (string $name) => "[{$targetLocale}] " . $name, $names);
            }
        };
        static::getContainer()->set(ProductTranslatorInterface::class, $this->dishAi);
        static::getContainer()->set(CategoryTranslatorInterface::class, $this->categoryAi);
        static::getContainer()->set(TagTranslatorInterface::class, $this->categoryAi);
    }

    protected function tearDown(): void
    {
        $this->otherVisit->close();
        if ($this->restaurantId !== null) {
            $id = $this->restaurantId;
            $this->conn->executeStatement('DELETE FROM product_translation WHERE product_id IN (SELECT p.id FROM product p JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM product WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category_translation WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category WHERE restaurant_id = ?', [$id]);
            $this->conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$id]);
        }

        parent::tearDown();
    }

    /** A Spanish menu: one category, one dish, nothing in French yet. */
    private function spanishMenu(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa candado');
        $this->slug = 'warm-lock-test-' . uniqid();
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setAdminLocale('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);
        $ct = new CategoryTranslation();
        $ct->setLocale('es');
        $ct->setName('Postres');
        $ct->setSource(CategoryTranslation::SOURCE_HUMAN);
        $category->addTranslation($ct);
        $this->em->persist($ct);

        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice(900);
        $product->setPosition(1);
        $category->addProduct($product);
        $this->em->persist($product);
        $pt = new ProductTranslation();
        $pt->setLocale('es');
        $pt->setName('Flan');
        $pt->setSource(ProductTranslation::SOURCE_HUMAN);
        $product->addTranslation($pt);
        $this->em->persist($pt);

        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $this->categoryId = $category->getId();
        $this->productId = $product->getId();
        $this->em->clear();
    }

    private function otherVisitLocks(): bool
    {
        return (bool) $this->otherVisit->fetchOne('SELECT pg_try_advisory_lock(CAST(? AS int4), CAST(? AS int4))', [$this->restaurantId, WarmTranslationLock::localeKey('fr')]);
    }

    private function otherVisitUnlocks(): void
    {
        $this->otherVisit->fetchOne('SELECT pg_advisory_unlock(CAST(? AS int4), CAST(? AS int4))', [$this->restaurantId, WarmTranslationLock::localeKey('fr')]);
    }

    /** What the other visit would have stored: the category's French name, and the dish's if $dishToo. */
    private function otherVisitStores(bool $dishToo): void
    {
        $this->otherVisit->insert('category_translation', ['category_id' => $this->categoryId, 'locale' => 'fr', 'name' => '[fr] Postres', 'source' => CategoryTranslation::SOURCE_AI]);
        if ($dishToo) {
            $this->otherVisit->insert('product_translation', ['product_id' => $this->productId, 'locale' => 'fr', 'name' => '[fr] Flan', 'source' => ProductTranslation::SOURCE_AI]);
        }
    }

    private function warm(string $locale = 'fr', string $ip = '127.0.0.1'): void
    {
        $this->client->request('POST', "/r/{$this->slug}/warm-translations?lang={$locale}", server: ['REMOTE_ADDR' => $ip]);
    }

    private function frenchDishName(): string|false
    {
        return $this->conn->fetchOne("SELECT name FROM product_translation WHERE product_id = ? AND locale = 'fr'", [$this->productId]);
    }

    public function testAloneItTranslatesAndReleasesTheLock(): void
    {
        $this->spanishMenu();

        $this->warm();

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->dishAi->calls);
        self::assertSame('[fr] Flan', $this->frenchDishName());
        self::assertSame(0, $this->clock->sleeps, 'nobody to wait for');
        self::assertTrue($this->otherVisitLocks(), 'released afterwards');
    }

    public function testWhileAnotherVisitTranslatesItWaitsAndLeavesWithoutAiWhenTimeRunsOut(): void
    {
        $this->spanishMenu();
        self::assertTrue($this->otherVisitLocks());
        $start = $this->clock->now();

        $this->warm();

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], json_decode($this->client->getResponse()->getContent(), true));
        self::assertSame(0, $this->dishAi->calls + $this->categoryAi->calls, 'never the AI');
        $waited = $this->clock->now()->getTimestamp() - $start->getTimestamp();
        self::assertGreaterThanOrEqual(TranslationDeadline::WARM_SECONDS, $waited);
        self::assertLessThan(TranslationDeadline::WARM_SECONDS + 1, $waited, 'not a moment past the deadline');
        self::assertFalse($this->frenchDishName());
    }

    public function testWaitsForTheOtherVisitThenFindsNothingLeftToTranslate(): void
    {
        $this->spanishMenu();
        self::assertTrue($this->otherVisitLocks());
        // 2 s in (8 polls), the other visit has stored everything and is done.
        $this->clock->onSleep = function (int $polls): void {
            if ($polls === 8) {
                $this->otherVisitStores(dishToo: true);
                $this->otherVisitUnlocks();
            }
        };

        $this->warm();

        self::assertResponseIsSuccessful();
        self::assertSame(8, $this->clock->sleeps);
        self::assertSame(0, $this->dishAi->calls + $this->categoryAi->calls, 'all done by the other visit');
        self::assertSame('[fr] Flan', $this->frenchDishName());
        self::assertTrue($this->otherVisitLocks(), 'released afterwards');
    }

    public function testWhenTheOtherVisitFailsHalfWayItDoesOnlyWhatIsLeft(): void
    {
        $this->spanishMenu();
        self::assertTrue($this->otherVisitLocks());
        // The other visit stored the category, then its dish batch failed.
        $this->clock->onSleep = function (int $polls): void {
            if ($polls === 4) {
                $this->otherVisitStores(dishToo: false);
                $this->otherVisitUnlocks();
            }
        };

        $this->warm();

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->categoryAi->calls, 'the category was already stored');
        self::assertSame(1, $this->dishAi->calls, 'only the dish');
        self::assertSame('[fr] Flan', $this->frenchDishName());
    }

    public function testTheLockIsReleasedWhenTheAiFails(): void
    {
        $this->spanishMenu();
        $this->dishAi->fail = true;

        $this->warm();

        self::assertResponseIsSuccessful('the warm-up must never break');
        self::assertSame(1, $this->dishAi->calls);
        self::assertSame('[fr] Postres', $this->conn->fetchOne("SELECT name FROM category_translation WHERE category_id = ? AND locale = 'fr'", [$this->categoryId]), 'what worked is kept');
        self::assertTrue($this->otherVisitLocks(), 'released even though the AI failed');
    }

    public function testTwentyEveryTenMinutesPerIp(): void
    {
        $this->spanishMenu();

        // An unsupported language: nothing to translate, but it counts.
        for ($i = 0; $i < 20; $i++) {
            $this->warm('xx', '10.9.8.7');
            self::assertResponseStatusCodeSame(400);
        }

        $this->warm('fr', '10.9.8.7');
        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->dishAi->calls + $this->categoryAi->calls);

        $this->warm('fr', '10.9.8.8');
        self::assertResponseIsSuccessful('another IP still gets through');
        self::assertSame(1, $this->dishAi->calls);
    }
}
