<?php

namespace App\Tests\Controller;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientAllergen;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Product;
use App\Entity\ProductGlobalIngredient;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\AllergenPresence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The product editor names every allergen in the Admin Panel's language —
 * the ALLERGENS list behind the chips the owner adds, the "Casos
 * especiales" picker, and the server-sent "Según tus ingredientes" list —
 * never in the menu's. The public menu keeps naming them in the
 * customer's language.
 *
 * Menu in Spanish, Admin Panel in French: "Lait", never "Leche", in the
 * editor; "Leche" on the Spanish menu.
 */
final class AdminAllergenNamesPanelLocaleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?int $restaurantId = null;
    private ?int $productId = null;
    private ?int $globalIngredientId = null;
    private string $slug;
    private int $milkId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $u = uniqid();

        $restaurant = new Restaurant();
        $restaurant->setName('Casa Paneles');
        $this->slug = 'allergen-panel-locale-' . $u;
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setAdminLocale('fr');
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
        $category->addTranslation($ct);
        $this->em->persist($ct);

        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice(500);
        $category->getProducts()->add($product);
        $this->em->persist($product);
        $pt = new ProductTranslation();
        $pt->setLocale('es');
        $pt->setName('Flan');
        $product->addTranslation($pt);
        $this->em->persist($pt);

        $milk = $this->em->getRepository(Allergen::class)->findOneBy(['code' => 'milk']);
        $this->milkId = $milk->getId();
        $global = new GlobalIngredient();
        $global->setCode('test-panel-locale-' . $u);
        $gt = new GlobalIngredientTranslation();
        $gt->setLocale('es');
        $gt->setName('Leche de test ' . $u);
        $global->addTranslation($gt);
        $link = new GlobalIngredientAllergen();
        $link->setAllergen($milk);
        $link->setPresence(AllergenPresence::CONTAINS);
        $global->addAllergenLink($link);
        $this->em->persist($global);

        $productLink = new ProductGlobalIngredient();
        $productLink->setGlobalIngredient($global);
        $productLink->setPosition(0);
        $product->addGlobalIngredientLink($productLink);
        $this->em->persist($productLink);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('allergen-panel-locale-' . $u . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->productId = $product->getId();
        $this->globalIngredientId = $global->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->restaurantId ? $this->em->getRepository(Restaurant::class)->find($this->restaurantId) : null;
        if ($restaurant) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush();
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
        }
        $global = $this->globalIngredientId ? $this->em->getRepository(GlobalIngredient::class)->find($this->globalIngredientId) : null;
        if ($global) {
            $this->em->remove($global);
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testTheEditorsAllergenListsUseThePanelLanguage(): void
    {
        $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        self::assertMatchesRegularExpression('/\{ id: ' . $this->milkId . ', code: "milk", icon: "[^"]*", name: "Lait" \}/u', $html, 'ALLERGENS (chips the owner adds)');
        self::assertMatchesRegularExpression('#<option value="' . $this->milkId . '">[^<]* Lait</option>#u', $html, '"Casos especiales" picker');
        self::assertStringNotContainsString('name: "Leche"', $html);

        $this->client->request('GET', '/admin/products/' . $this->productId);
        self::assertResponseIsSuccessful();
        $computed = json_decode($this->client->getResponse()->getContent(), true)['allergensComputed'];
        self::assertSame(['Lait'], array_column($computed, 'name'), '"Según tus ingredientes"');
    }

    public function testThePublicMenuStillNamesThemInTheCustomersLanguage(): void
    {
        $this->client->request('GET', '/r/' . $this->slug . '?lang=es&currency=EUR&mtw=1');
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        self::assertMatchesRegularExpression('#data-allergen="milk"[^>]*>.*?Leche</button>#us', $html);
        self::assertStringNotContainsString('Lait', $html);
    }
}
