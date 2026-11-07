<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A Carta category's name (POST /admin/categories/{id}/edit) follows the
 * owner/AI rule: a real rename — any change, a capital letter too — makes
 * it the owner's ('human') and drops its AI translations (the owner's rows
 * stay); saving the same name (only the type changed) leaves it as it was,
 * an AI name staying 'ai', nothing redone.
 */
final class CartaCategoryNameTranslationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private int $restaurantId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);

        // An English menu that used to be Spanish: the Spanish name is the
        // owner's, the English one (the main language now) and the French
        // one were written by the AI.
        $restaurant = new Restaurant();
        $restaurant->setName('Carta Category Name Translation Test');
        $restaurant->setSlug('carta-category-name-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('en');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setRestaurant($restaurant);
        $category->setActive(true);
        $this->em->persist($category);
        foreach ([['es', 'Postres', 'human'], ['en', 'Desserts', 'ai'], ['fr', 'Desserts', 'ai']] as [$locale, $name, $source]) {
            $t = new CategoryTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $category->addTranslation($t);
            $this->em->persist($t);
        }

        $user = new User();
        $user->setEmail('carta-category-name-test-' . uniqid() . '@example.test');
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
        $id = $this->restaurantId;
        $this->conn->executeStatement('DELETE FROM category_translation WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
        $this->conn->executeStatement('DELETE FROM category WHERE restaurant_id = ?', [$id]);
        $this->conn->executeStatement('DELETE FROM "user" WHERE restaurant_id = ?', [$id]);
        $this->conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$id]);

        parent::tearDown();
    }

    public function testSavingTheSameNameLeavesItAiAndKeepsItsTranslations(): void
    {
        $this->save('Desserts', type: 'food');

        self::assertSame([
            'en' => ['Desserts', 'ai'],
            'es' => ['Postres', 'human'],
            'fr' => ['Desserts', 'ai'],
        ], $this->rows(), 'an AI name stays AI; nothing is redone');
        self::assertSame('food', $this->conn->fetchOne('SELECT type FROM category WHERE id = ?', [$this->categoryId]), 'the rest was saved');
    }

    public function testARenameMakesTheNameTheOwnersAndDropsItsAiTranslations(): void
    {
        $version = $this->version();

        $this->save('Sweets');

        self::assertSame([
            'en' => ['Sweets', 'human'],
            'es' => ['Postres', 'human'],
        ], $this->rows(), 'French (AI, from the old name) gone; the owner\'s Spanish kept');
        self::assertGreaterThan($version, $this->version());
    }

    public function testAChangeOfCaseIsARenameToo(): void
    {
        $this->save('DESSERTS');

        self::assertSame(['DESSERTS', 'human'], $this->rows()['en']);
        self::assertArrayNotHasKey('fr', $this->rows());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function save(string $name, string $type = ''): void
    {
        $this->client->request('POST', "/admin/categories/{$this->categoryId}/edit", content: json_encode(['name' => $name, 'type' => $type]));
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, array{0: string, 1: string}> locale => [name, source] */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->conn->fetchAllNumeric('SELECT locale, name, source FROM category_translation WHERE category_id = ? ORDER BY locale', [$this->categoryId]) as [$locale, $name, $source]) {
            $rows[$locale] = [$name, $source];
        }

        return $rows;
    }

    private function version(): int
    {
        return (int) $this->conn->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$this->restaurantId]);
    }
}
