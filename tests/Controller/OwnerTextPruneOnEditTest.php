<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\OwnerTextTranslationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin saves that can leave an owner text behind delete its
 * translations right after (OwnerTextTranslationPruner): the tagline
 * (Configuración), a price label or variant (Carta, dish save), a deleted
 * dish, a set menu's description, a renamed or deleted section. A text
 * still used somewhere else stays; nothing goes while a main-language
 * change is half done. No AI involved: the rows are written directly.
 */
final class OwnerTextPruneOnEditTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;
    private int $restaurantId;
    private int $categoryId;
    private int $wineId;
    private int $beerId;
    private int $menuId;
    private int $firstsId;
    private int $secondsId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);

        // Spanish: a tagline; a wine (Botella / Copa) and a beer (Copa /
        // Tercio); a set menu with a description and two sections.
        $restaurant = new Restaurant();
        $restaurant->setName('Owner Text Prune Test');
        $restaurant->setSlug('owner-text-prune-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setSetMenusEnabled(true);
        $restaurant->setTagline('Cocina de mercado.');
        $this->em->persist($restaurant);

        $drinks = new Category();
        $drinks->setActive(true);
        $restaurant->addCategory($drinks);
        $this->em->persist($drinks);
        $this->label($drinks, CategoryTranslation::class, 'Bebidas');
        $wine = $this->dish($drinks, 'Vino tinto', 'Botella', 'Copa');
        $beer = $this->dish($drinks, 'Cerveza', 'Tercio', 'Copa');

        $menu = new Category();
        $menu->setActive(true);
        $menu->setMenuPrice(1500);
        $menu->setMenuDescription('Pan, bebida y postre');
        $restaurant->addCategory($menu);
        $this->em->persist($menu);
        $this->label($menu, CategoryTranslation::class, 'Menú del día');
        $sections = [];
        foreach (['Primeros', 'Segundos'] as $i => $label) {
            $sections[$i] = new MenuSection();
            $sections[$i]->setLabel($label);
            $sections[$i]->setPosition($i);
            $menu->addMenuSection($sections[$i]);
            $this->em->persist($sections[$i]);
        }

        $user = new User();
        $user->setEmail('owner-text-prune-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $repository = static::getContainer()->get(OwnerTextTranslationRepository::class);
        foreach (['Cocina de mercado.', 'Botella', 'Copa', 'Tercio', 'Pan, bebida y postre', 'Primeros', 'Segundos'] as $text) {
            $repository->insertIfMissing($restaurant, 'fr', 'es', $text, "[fr] {$text}");
        }

        $this->restaurantId = $restaurant->getId();
        $this->categoryId = $drinks->getId();
        $this->wineId = $wine->getId();
        $this->beerId = $beer->getId();
        $this->menuId = $menu->getId();
        $this->firstsId = $sections[0]->getId();
        $this->secondsId = $sections[1]->getId();
        $this->em->clear();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $id = $this->restaurantId;
        $this->conn->executeStatement('DELETE FROM product_translation WHERE product_id IN (SELECT p.id FROM product p JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?)', [$id]);
        $this->conn->executeStatement('DELETE FROM product WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
        $this->conn->executeStatement('DELETE FROM menu_section WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
        $this->conn->executeStatement('DELETE FROM category_translation WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
        $this->conn->executeStatement('DELETE FROM category WHERE restaurant_id = ?', [$id]);
        $this->conn->executeStatement('DELETE FROM "user" WHERE restaurant_id = ?', [$id]);
        // owner_text_translation goes with the restaurant (ON DELETE CASCADE).
        $this->conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$id]);

        parent::tearDown();
    }

    private function label(object $owner, string $class, string $name): void
    {
        $t = new $class();
        $t->setLocale('es');
        $t->setName($name);
        $owner->addTranslation($t);
        $this->em->persist($t);
    }

    private function dish(Category $category, string $name, string $baseLabel, string $variantLabel): Product
    {
        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice(1000);
        $product->setBasePriceLabel($baseLabel);
        $variant = new ProductPriceVariant();
        $variant->setLabel($variantLabel);
        $variant->setPrice(400);
        $variant->setPosition(0);
        $product->addPriceVariant($variant);
        $category->addProduct($product);
        $this->em->persist($product);
        $this->label($product, ProductTranslation::class, $name);

        return $product;
    }

    /** @return list<string> the source texts with a row, sorted */
    private function texts(): array
    {
        return $this->conn->fetchFirstColumn('SELECT source_text FROM owner_text_translation WHERE restaurant_id = ? ORDER BY source_text', [$this->restaurantId]);
    }

    /** @param list<string> $gone */
    private function assertOnlyGone(array $gone): void
    {
        $all = ['Botella', 'Cocina de mercado.', 'Copa', 'Pan, bebida y postre', 'Primeros', 'Segundos', 'Tercio'];
        self::assertSame(array_values(array_diff($all, $gone)), $this->texts());
    }

    private function json(string $url, array $data = []): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
        self::assertResponseIsSuccessful();
    }

    private function saveTagline(string $tagline): void
    {
        $token = $this->client->request('GET', '/admin/settings')->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/settings', [
            '_token'  => $token,
            'section' => 'info',
            'name'    => 'Owner Text Prune Test',
            'tagline' => $tagline,
        ]);
        self::assertResponseRedirects();
    }

    public function testANewTaglineDropsTheOldOnesTranslations(): void
    {
        $this->saveTagline('Cocina de temporada.');

        $this->assertOnlyGone(['Cocina de mercado.']);
    }

    public function testSavingTheSameTaglineKeepsItsTranslations(): void
    {
        $this->saveTagline('Cocina de mercado.');

        $this->assertOnlyGone([]);
    }

    /** "Copa" is on both drinks: rewritten on the wine, it stays for the beer. */
    public function testARewrittenPriceLabelGoesButOneStillUsedElsewhereStays(): void
    {
        $this->json('/admin/products/save', [
            'id'             => $this->wineId,
            'categoryId'     => $this->categoryId,
            'basePrice'      => 10.0,
            'translations'   => ['es' => ['name' => 'Vino tinto', 'description' => null]],
            'basePriceLabel' => 'Botella 75 cl',
            'priceVariants'  => [['label' => 'Copa 15 cl', 'price' => 4.0]],
        ]);

        $this->assertOnlyGone(['Botella']);
    }

    public function testADeletedDishTakesTheTextsOnlyItHad(): void
    {
        $this->json("/admin/products/{$this->beerId}/delete");

        $this->assertOnlyGone(['Tercio']);
    }

    public function testASetMenusNewDescriptionAndItsSectionsChanges(): void
    {
        $this->json("/admin/menus/{$this->menuId}/edit", ['name' => 'Menú del día', 'menuPrice' => '15', 'menuDescription' => 'Pan y bebida']);
        $this->assertOnlyGone(['Pan, bebida y postre']);

        $this->json("/admin/menu-sections/{$this->firstsId}/rename", ['label' => 'Entrantes']);
        $this->assertOnlyGone(['Pan, bebida y postre', 'Primeros']);

        $this->json("/admin/menu-sections/{$this->secondsId}/delete");
        $this->assertOnlyGone(['Pan, bebida y postre', 'Primeros', 'Segundos']);
    }

    public function testNothingGoesWhileAMainLanguageChangeIsHalfDone(): void
    {
        $this->conn->executeStatement("UPDATE restaurant SET language_change_to = 'en' WHERE id = ?", [$this->restaurantId]);

        $this->saveTagline('Cocina de temporada.');
        $this->json("/admin/products/{$this->beerId}/delete");

        $this->assertOnlyGone([]);
    }
}
