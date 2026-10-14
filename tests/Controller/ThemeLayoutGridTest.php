<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The "Cuadrícula" public-menu view (Restaurant::$layout == 'grid'): saved
 * from /admin/theme, maison-only. Not a template of its own — MenuController
 * renders the standard layout and adds .cards-grid--2up only when the stored
 * (or previewed) layout is 'grid' AND the theme is maison; any other theme
 * with 'grid' stored gets the plain list.
 */
final class ThemeLayoutGridTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function makeRestaurant(string $theme, string $layout = 'standard'): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Grid Layout Test');
        $restaurant->setSlug('grid-layout-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme($theme);
        $restaurant->setLayout($layout);
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);

        $categoryTranslation = new CategoryTranslation();
        $categoryTranslation->setLocale('es');
        $categoryTranslation->setName('Entrantes');
        $category->addTranslation($categoryTranslation);
        $this->em->persist($categoryTranslation);

        $product = new Product();
        $product->setBasePrice(1200);
        $product->setActive(true);
        $category->addProduct($product);
        $this->em->persist($product);

        $productTranslation = new ProductTranslation();
        $productTranslation->setLocale('es');
        $productTranslation->setName('Plato de prueba');
        $product->addTranslation($productTranslation);
        $this->em->persist($productTranslation);

        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $restaurant;
    }

    private function loginOwnerOf(Restaurant $restaurant): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('grid-layout-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function applyLayout(array $body): void
    {
        $this->client->request('POST', '/admin/theme/apply', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
    }

    private function storedLayout(Restaurant $restaurant): string
    {
        $this->em->clear();

        return $this->em->getRepository(Restaurant::class)->find($restaurant->getId())->getLayout();
    }

    public function testMaisonRestaurantCanSaveGridAndReadItBack(): void
    {
        $restaurant = $this->makeRestaurant('maison');
        $this->loginOwnerOf($restaurant);

        $this->applyLayout(['layout' => 'grid']);

        self::assertResponseIsSuccessful();
        self::assertSame('grid', json_decode($this->client->getResponse()->getContent(), true)['layout']);
        self::assertSame('grid', $this->storedLayout($restaurant));
    }

    public function testGridIsRejectedForANonMaisonTheme(): void
    {
        $restaurant = $this->makeRestaurant('classic-dark');
        $this->loginOwnerOf($restaurant);

        $this->applyLayout(['layout' => 'grid']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('standard', $this->storedLayout($restaurant));
    }

    public function testGridTogetherWithSwitchingToMaisonIsAccepted(): void
    {
        $restaurant = $this->makeRestaurant('classic-dark');
        $this->loginOwnerOf($restaurant);

        $this->applyLayout(['layout' => 'grid', 'theme' => 'maison']);

        self::assertResponseIsSuccessful();
        self::assertSame('grid', $this->storedLayout($restaurant));
    }

    public function testSwitchingThemeAwayFromMaisonKeepsGridStoredAndIsNotBlocked(): void
    {
        $restaurant = $this->makeRestaurant('maison', 'grid');
        $this->loginOwnerOf($restaurant);

        $this->applyLayout(['theme' => 'ocean']);

        self::assertResponseIsSuccessful();
        self::assertSame('grid', $this->storedLayout($restaurant));
    }

    public function testMaisonWithGridRendersTheGridVariant(): void
    {
        $restaurant = $this->makeRestaurant('maison', 'grid');

        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cards-grid.cards-grid--2up'));
    }

    public function testMaisonWithStandardRendersTheList(): void
    {
        $restaurant = $this->makeRestaurant('maison');

        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cards-grid'));
        self::assertCount(0, $crawler->filter('.cards-grid--2up'));
        self::assertStringNotContainsString('cards-grid--2up', $this->client->getResponse()->getContent(), 'the grid CSS must not be emitted for the list view');
    }

    public function testNonMaisonThemeWithGridStoredServesTheList(): void
    {
        $restaurant = $this->makeRestaurant('classic-dark', 'grid');

        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cards-grid'));
        self::assertStringNotContainsString('cards-grid--2up', $this->client->getResponse()->getContent());
    }

    public function testOwnerCanPreviewGridWithoutChangingTheStoredLayout(): void
    {
        $restaurant = $this->makeRestaurant('maison');
        $this->loginOwnerOf($restaurant);

        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es&preview_layout=grid');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cards-grid.cards-grid--2up'));
        self::assertSame('standard', $this->storedLayout($restaurant));
    }

    public function testAdminPickerShowsGridDisabledForANonMaisonTheme(): void
    {
        $restaurant = $this->makeRestaurant('classic-dark');
        $this->loginOwnerOf($restaurant);

        $crawler = $this->client->request('GET', '/admin/structure');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#lcard-grid.is-disabled'));
        self::assertCount(2, $crawler->filter('#lcard-grid button[disabled]'));
        self::assertCount(0, $crawler->filter('#lcard-standard.is-disabled'));
    }

    public function testAdminPickerShowsGridEnabledForMaison(): void
    {
        $restaurant = $this->makeRestaurant('maison');
        $this->loginOwnerOf($restaurant);

        $crawler = $this->client->request('GET', '/admin/structure');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#lcard-grid'));
        self::assertCount(0, $crawler->filter('#lcard-grid.is-disabled'));
        self::assertCount(0, $crawler->filter('#lcard-grid button[disabled]'));
    }

    public function testStructureAndThemeAreSeparateAdminScreens(): void
    {
        $restaurant = $this->makeRestaurant('maison');
        $this->loginOwnerOf($restaurant);

        $crawler = $this->client->request('GET', '/admin/structure');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[id^="lcard-"]'));
        self::assertCount(0, $crawler->filter('[id^="tcard-"]'));
        self::assertCount(1, $crawler->filter('a.nav-item.active[href="/admin/structure"]'));
        self::assertCount(1, $crawler->filter('a.bottom-nav-tab.active[href="/admin/structure"]'), 'mobile bottom nav must link to Estructura too');

        $crawler = $this->client->request('GET', '/admin/theme');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[id^="lcard-"]'));
        self::assertCount(8, $crawler->filter('[id^="tcard-"]'));
    }

    /**
     * Turns the restaurant's one test dish into a photo card (a maison dish
     * without image renders as a .dish-row, not the card this covers) with
     * base price 12,00 € plus the given variants, in cents.
     */
    private function makeMultiprice(Restaurant $restaurant, array $variantPrices): void
    {
        $product = $this->em->getRepository(Product::class)->findOneBy(['category' => $restaurant->getCategories()->first()]);
        $product->setImage('grid-test.jpg');
        $product->setBasePriceLabel('Botella');
        foreach ($variantPrices as $i => $price) {
            $variant = new ProductPriceVariant();
            $variant->setLabel('Variante ' . $i);
            $variant->setPrice($price);
            $variant->setPosition($i);
            $product->addPriceVariant($variant);
        }
        $this->em->flush();
        $this->em->clear();
    }

    public function testGridMultipriceCardShowsFromAndTheLowestPriceButTheListDoesNot(): void
    {
        $grid = $this->makeRestaurant('maison', 'grid');
        $this->makeMultiprice($grid, [2210, 450]);

        $crawler = $this->client->request('GET', '/r/' . $grid->getSlug() . '?lang=es&currency=EUR');

        self::assertResponseIsSuccessful();
        $bottom = $crawler->filter('.cards-grid--2up .dish-card .dish-bottom');
        self::assertCount(1, $bottom);
        self::assertSame('Desde', trim($bottom->filter('.dish-from')->text()));
        self::assertSame('4,50€', trim($bottom->filter('.dish-price')->text()));
        self::assertCount(0, $bottom->filter('.price-stack'), 'the grid card shows only the lowest price');
        self::assertCount(1, $crawler->filter('.dish-detail-price .price-stack'), 'the detail sheet still lists every price');

        $list = $this->makeRestaurant('maison');
        $this->makeMultiprice($list, [2210, 450]);

        $crawler = $this->client->request('GET', '/r/' . $list->getSlug() . '?lang=es&currency=EUR');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.dish-from'));
        self::assertCount(1, $crawler->filter('.dish-card .dish-bottom .price-stack'), 'the list keeps every price row');
    }

    public function testGridMultipriceWithAllPricesEqualShowsNoFrom(): void
    {
        $restaurant = $this->makeRestaurant('maison', 'grid');
        $this->makeMultiprice($restaurant, [1200]);

        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es&currency=EUR');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.dish-from'));
        self::assertSame('12,00€', trim($crawler->filter('.cards-grid--2up .dish-card .dish-bottom .dish-price')->text()));
    }
}
