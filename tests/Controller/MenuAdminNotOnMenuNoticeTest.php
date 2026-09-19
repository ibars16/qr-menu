<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "No se muestra en la carta: <motivo>" notice on dish rows
 * (admin/_product_menu_notice.html.twig), on both screens that share the
 * product row: Carta (/admin/menu) and a Menús fijos editor
 * (/admin/menus/{id}). Only an ACTIVE dish the public menu won't show gets
 * one — a dish the owner hid on purpose already reads "Oculto" honestly.
 * Real HTTP through the real admin routes, test database only.
 */
final class MenuAdminNotOnMenuNoticeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $restaurantId;
    private int $menuCategoryId;
    private int $menuOkId;

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, int> dish key => product id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Not On Menu Notice Test');
        $restaurant->setSlug('not-on-menu-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setSetMenusEnabled(true); // Menús fijos screen is gated behind it
        $this->em->persist($restaurant);

        $normal    = $this->category($restaurant, 'Normal', active: true);
        $hiddenCat = $this->category($restaurant, 'Categoría oculta', active: false);
        $menuCat   = $this->category($restaurant, 'Menú oculto', active: false, fixedPrice: true);
        $menuOk    = $this->category($restaurant, 'Menú visible', active: true, fixedPrice: true);

        $this->dish('visible', $normal, 'PlatoVisible', 1000);
        $this->dish('price_zero', $normal, 'PlatoPrecioCero', 0);
        $this->dish('hidden_by_owner', $normal, 'PlatoOcultoPorElDueno', 0, active: false); // would ALSO be price 0
        $this->dish('no_translation', $normal, null, 1000);
        $this->dish('in_hidden_category', $hiddenCat, 'PlatoEnCategoriaOculta', 1000);

        $sectionHidden = $this->section($menuCat);
        $this->dish('menu_in_hidden_menu', $menuCat, 'PlatoMenuOculto', 0, section: $sectionHidden);
        $sectionOk = $this->section($menuOk);
        $this->dish('menu_price_zero_ok', $menuOk, 'PlatoMenuPrecioCeroOk', 0, section: $sectionOk);
        $this->dish('menu_hidden_by_owner', $menuOk, 'PlatoMenuOcultoPorElDueno', 0, active: false, section: $sectionOk);

        $owner = new User();
        $owner->setEmail('not-on-menu-' . uniqid() . '@example.test');
        $owner->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($owner, 'irrelevant-password-1'));
        $owner->setRoles([User::ROLE_OWNER]);
        $owner->setRestaurant($restaurant);
        $this->em->persist($owner);
        $this->em->flush();

        $this->restaurantId   = $restaurant->getId();
        $this->menuCategoryId = $menuCat->getId();
        $this->menuOkId       = $menuOk->getId();
        foreach ($this->products as $key => $product) {
            $this->ids[$key] = $product->getId();
        }

        // Reload from the DB rather than reuse these freshly-built objects —
        // see MenuAdminMissingPhotoNoticeTest::makeRestaurantWithDishes().
        $this->em->clear();
        $this->client->loginUser($this->em->getRepository(User::class)->find($owner->getId()));
    }

    protected function tearDown(): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        if ($restaurant) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush(); // dishes first: they reference their section
                foreach ($this->em->getRepository(MenuSection::class)->findBy(['category' => $category]) as $section) {
                    $this->em->remove($section);
                }
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        parent::tearDown();
    }

    private function category(Restaurant $restaurant, string $name, bool $active, bool $fixedPrice = false): Category
    {
        $category = new Category();
        $category->setActive($active);
        if ($fixedPrice) {
            $category->setMenuPrice(1500);
        }
        $restaurant->addCategory($category);
        $this->em->persist($category);

        $t = new CategoryTranslation();
        $t->setLocale('es');
        $t->setName($name);
        $category->addTranslation($t);
        $this->em->persist($t);

        return $category;
    }

    private function section(Category $menu): MenuSection
    {
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $section->setPosition(0);
        $menu->addMenuSection($section);
        $this->em->persist($section);

        return $section;
    }

    private function dish(string $key, Category $category, ?string $name, int $price, bool $active = true, ?MenuSection $section = null): void
    {
        $product = new Product();
        $product->setBasePrice($price);
        $product->setActive($active);
        $category->addProduct($product);
        $section?->addProduct($product);
        $this->em->persist($product);

        if ($name !== null) {
            $t = new ProductTranslation();
            $t->setLocale('es');
            $t->setName($name);
            $product->addTranslation($t);
            $this->em->persist($t);
        }

        $this->products[$key] = $product;
    }

    private function reason(string $key): string
    {
        return static::getContainer()->get(TranslatorInterface::class)->trans("product.hidden_reason.$key", [], 'admin_menu', 'es');
    }

    /** The notice text on dish row $id, or null when the row has none. */
    private function notice(Crawler $page, int $id): ?string
    {
        $row = $page->filter(sprintf('.product-row[data-id="%d"]', $id));
        self::assertCount(1, $row, "dish row $id must exist on the page");
        $notice = $row->filter('.menu-notice');

        return $notice->count() === 0 ? null : trim(preg_replace('/\s+/', ' ', $notice->text()));
    }

    private function expected(string $reasonKey): string
    {
        return static::getContainer()->get(TranslatorInterface::class)
            ->trans('product.not_on_menu', ['%reason%' => $this->reason($reasonKey)], 'admin_menu', 'es');
    }

    public function testCartaShowsTheNoticeOnlyForActiveDishesTheMenuWontShow(): void
    {
        $page = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();

        self::assertNull($this->notice($page, $this->ids['visible']), 'a normal visible dish has no notice');
        self::assertSame($this->expected('price_zero'), $this->notice($page, $this->ids['price_zero']));
        self::assertSame($this->expected('no_translation'), $this->notice($page, $this->ids['no_translation']));
        self::assertSame($this->expected('category_hidden'), $this->notice($page, $this->ids['in_hidden_category']));
        // The owner hid it on purpose: "Oculto" is honest, even though it is also priced 0.
        self::assertNull($this->notice($page, $this->ids['hidden_by_owner']));
    }

    public function testCartaNoticeCarriesTheReasonInPlainText(): void
    {
        $page = $this->client->request('GET', '/admin/menu');

        // Reads as a sentence, not a raw translation key.
        self::assertSame('No se muestra en la carta: su precio es 0', $this->notice($page, $this->ids['price_zero']));
        self::assertSame('No se muestra en la carta: su categoría está oculta', $this->notice($page, $this->ids['in_hidden_category']));
    }

    public function testMenusEditorShowsTheNoticeToo(): void
    {
        // Hidden menu: its dishes are active but the menu (their category) is not.
        $page = $this->client->request('GET', '/admin/menus/' . $this->menuCategoryId);
        self::assertResponseIsSuccessful();
        self::assertSame($this->expected('category_hidden'), $this->notice($page, $this->ids['menu_in_hidden_menu']));

        // Visible menu: a €0 dish is legitimate here (no notice), and one the owner hid has none either.
        $page = $this->client->request('GET', '/admin/menus/' . $this->menuOkId);
        self::assertResponseIsSuccessful();
        self::assertNull($this->notice($page, $this->ids['menu_price_zero_ok']));
        self::assertNull($this->notice($page, $this->ids['menu_hidden_by_owner']));
    }
}
