<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\CategoryTranslatorInterface;
use App\Service\IndependentReviewTranslator;
use App\Service\MenuLanguageChanger;
use App\Service\MenuLanguageDetectorInterface;
use App\Service\ProductTranslatorInterface;
use App\Service\TagTranslatorInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A set menu's name on the Menús screen (POST /admin/menus/{id}/edit)
 * follows the owner/AI rule, like a Carta category's: a real rename makes
 * the name the owner's ('human') and drops its AI translations (the
 * owner's rows stay), so they're redone from the new name; saving the same
 * name changes nothing about it. A later main-language change never
 * overwrites the owner's name. The AI is faked ("[<locale>] <text>").
 */
final class MenusNameTranslationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private int $restaurantId;
    private int $menuId;

    private object $detector;
    private object $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);

        $this->detector = new class implements MenuLanguageDetectorInterface {
            public ?string $answer = null;

            public function detect(array $texts): ?string
            {
                return $this->answer;
            }

            /** Every text reads as English. */
            public function detectEach(array $texts): ?array
            {
                return array_map(static fn () => 'en', $texts);
            }
        };
        $this->ai = new class implements ProductTranslatorInterface, CategoryTranslatorInterface, TagTranslatorInterface, IndependentReviewTranslator {
            public function withIndependentReview(): static
            {
                return $this;
            }

            public function translate(array $items, string $targetLocale, array $sourceLocales = []): array
            {
                return array_map(static fn ($i) => is_array($i)
                    ? ['name' => "[{$targetLocale}] " . $i['name'], 'description' => null]
                    : "[{$targetLocale}] " . $i, $items);
            }
        };
        $container = static::getContainer();
        $container->set(MenuLanguageDetectorInterface::class, $this->detector);
        $container->set(ProductTranslatorInterface::class, $this->ai);
        $container->set(CategoryTranslatorInterface::class, $this->ai);
        $container->set(TagTranslatorInterface::class, $this->ai);

        // An English menu that used to be Spanish: the Spanish name is the
        // owner's, the English one (the main language now) and the French
        // one were written by the AI.
        $restaurant = new Restaurant();
        $restaurant->setName('Menus Name Translation Test');
        $restaurant->setSlug('menus-name-translation-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('en');
        $restaurant->setSetMenusEnabled(true);
        $this->em->persist($restaurant);

        $menu = new Category();
        $menu->setRestaurant($restaurant);
        $menu->setActive(true);
        $menu->setMenuPrice(1500);
        $this->em->persist($menu);
        foreach ([['es', 'Menú del día', 'human'], ['en', 'Daily menu', 'ai'], ['fr', 'Menu du jour', 'ai']] as [$locale, $name, $source]) {
            $t = new CategoryTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $menu->addTranslation($t);
            $this->em->persist($t);
        }

        $user = new User();
        $user->setEmail('menus-name-translation-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->menuId = $menu->getId();
        $this->em->clear();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $id = $this->restaurantId;
        $this->conn->executeStatement('DELETE FROM category_translation WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
        $this->conn->executeStatement('DELETE FROM category WHERE restaurant_id = ?', [$id]);
        $this->conn->executeStatement('DELETE FROM "user" WHERE restaurant_id = ?', [$id]);
        // owner_text_translation goes with the restaurant (ON DELETE CASCADE).
        $this->conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$id]);

        parent::tearDown();
    }

    public function testARenameMakesTheNameTheOwnersAndDropsItsAiTranslations(): void
    {
        $version = $this->version();

        $this->save('Lunch menu');

        self::assertSame([
            'en' => ['Lunch menu', 'human'],
            'es' => ['Menú del día', 'human'],
        ], $this->rows(), 'French (AI, from the old name) gone; the owner\'s Spanish kept');
        self::assertGreaterThan($version, $this->version());
    }

    public function testSavingTheSameNameChangesNothingAboutIt(): void
    {
        $this->save('Daily menu', price: '18.50');

        self::assertSame([
            'en' => ['Daily menu', 'ai'],
            'es' => ['Menú del día', 'human'],
            'fr' => ['Menu du jour', 'ai'],
        ], $this->rows(), 'an AI name stays AI; nothing is redone');
        self::assertSame(1850, (int) $this->conn->fetchOne('SELECT menu_price FROM category WHERE id = ?', [$this->menuId]), 'the price itself was saved');
    }

    /** A rename that only changes the case still is a rename. */
    public function testAnyChangeToTheTextCounts(): void
    {
        $this->save('Daily Menu');

        self::assertSame(['Daily Menu', 'human'], $this->rows()['en']);
        self::assertArrayNotHasKey('fr', $this->rows());
    }

    public function testANewMenusNameIsTheOwners(): void
    {
        $this->client->request('POST', '/admin/menus/create', content: json_encode(['name' => 'Tasting menu', 'menuPrice' => '45']));
        self::assertResponseIsSuccessful();
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        self::assertSame(['Tasting menu', 'human'], $this->conn->fetchNumeric("SELECT name, source FROM category_translation WHERE category_id = ? AND locale = 'en'", [$id]));
    }

    /**
     * Renamed by the owner, then English → French → English: the owner's
     * English name comes back as they wrote it (an 'ai' one would have been
     * translated again from the French on the way back).
     */
    public function testALaterMainLanguageChangeNeverOverwritesTheOwnersName(): void
    {
        $this->save('Lunch menu');

        $this->changeLanguage('fr');
        $rows = $this->rows();
        self::assertSame(['Lunch menu', 'human'], $rows['en']);
        self::assertSame(['[fr] Lunch menu', 'ai'], $rows['fr'], 'French translated from the owner\'s new name');

        $this->changeLanguage('en');
        self::assertSame(['Lunch menu', 'human'], $this->rows()['en']);
        self::assertSame(['Menú del día', 'human'], $this->rows()['es']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function save(string $name, string $price = '15'): void
    {
        $this->em->clear();
        $this->client->request('POST', "/admin/menus/{$this->menuId}/edit", content: json_encode(['name' => $name, 'menuPrice' => $price]));
        self::assertResponseIsSuccessful();
    }

    private function changeLanguage(string $to): void
    {
        $changer = static::getContainer()->get(MenuLanguageChanger::class);
        $this->em->clear();
        $status = $changer->start($this->em->find(Restaurant::class, $this->restaurantId), $to);
        for ($i = 0; $status === MenuLanguageChanger::CONVERTING && $i < 20; $i++) {
            $this->em->clear();
            $result = $changer->step($this->em->find(Restaurant::class, $this->restaurantId));
            self::assertFalse($result['error']);
            if ($result['done']) {
                break;
            }
        }
        $this->em->clear();
        self::assertSame($to, $this->em->find(Restaurant::class, $this->restaurantId)->getDefaultLanguage());
    }

    /** @return array<string, array{0: string, 1: string}> locale => [name, source] */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->conn->fetchAllNumeric('SELECT locale, name, source FROM category_translation WHERE category_id = ? ORDER BY locale', [$this->menuId]) as [$locale, $name, $source]) {
            $rows[$locale] = [$name, $source];
        }

        return $rows;
    }

    private function version(): int
    {
        return (int) $this->conn->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$this->restaurantId]);
    }
}
