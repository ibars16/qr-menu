<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Two rows with the same position (a new one gets count() after a delete)
 * always come out oldest first — by id — for tags, fixed-price menus, their
 * sections and the dishes in a section, not in whatever order the database
 * or the collection happens to hold them.
 *
 * Tags: the test makes the tie real in the database — a no-op UPDATE of the
 * OLDER row moves it physically after the newer one, so a load without
 * "id" in its ORDER BY returns the newer one first.
 */
final class OrderTieBreakTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private int $restaurantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Order Tie Break Test');
        $this->restaurant->setSlug('order-tie-break-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->em->persist($this->restaurant);
        $this->em->flush();
        $this->restaurantId = $this->restaurant->getId();

        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('order-tie-break-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($this->restaurant);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        if ($restaurant) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
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

    /** No-op UPDATE: moves the row to the end of the table's physical order. */
    private function moveToEnd(string $table, int $id): void
    {
        $this->em->getConnection()->executeStatement("UPDATE {$table} SET position = position WHERE id = :id", ['id' => $id]);
    }

    public function testTagsWithTheSamePositionComeOutOldestFirst(): void
    {
        $older = new ProductTag($this->restaurant, 'tie-older-' . uniqid());
        $newer = new ProductTag($this->restaurant, 'tie-newer-' . uniqid());
        foreach ([$older, $newer] as $tag) {
            $tag->setPosition(50);
            $this->restaurant->addProductTag($tag);
            $this->em->persist($tag);
            $this->em->flush();
        }
        $olderId = $older->getId();
        $newerId = $newer->getId();
        $this->moveToEnd('product_tag', $olderId);
        $this->em->clear();

        // The collection: the dish editor lists it as is, and the public
        // menu / Etiquetas sorts start from it (plus their own id tie-break).
        // The Etiquetas screen itself is hidden (404), so it isn't fetched here.
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        $ids = array_values(array_filter(
            array_map(static fn (ProductTag $t) => $t->getId(), $restaurant->getProductTags()->toArray()),
            static fn (int $id) => in_array($id, [$olderId, $newerId], true)
        ));
        self::assertSame([$olderId, $newerId], $ids);

    }

    // Menus and sections: PostgreSQL keeps returning these in insertion
    // order through their index (the no-op UPDATE trick doesn't move them),
    // so the database can't be made to hand back the newer one first. The
    // rule is checked on objects instead, fed newest-first on purpose.

    private static function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }

    public function testMenusWithTheSamePositionComeOutOldestFirst(): void
    {
        $restaurant = new Restaurant();
        foreach ([20, 10] as $id) {
            $menu = self::withId(new Category(), $id);
            $menu->setMenuPrice(1500);
            $menu->setPosition(0);
            $restaurant->addCategory($menu);
        }

        $menusFor = new \ReflectionMethod(\App\Controller\Admin\MenusController::class, 'menusFor');
        $controller = (new \ReflectionClass(\App\Controller\Admin\MenusController::class))->newInstanceWithoutConstructor();
        $menus = $menusFor->invoke($controller, $restaurant);

        self::assertSame([10, 20], array_map(static fn (Category $c) => $c->getId(), $menus));
    }

    public function testSectionsAndTheirDishesWithTheSamePositionComeOutOldestFirst(): void
    {
        $menu = new Category();
        $menu->setMenuPrice(1500);
        $sections = [];
        foreach ([20, 10] as $id) {
            $section = self::withId(new MenuSection(), $id);
            $section->setLabel('S' . $id);
            $section->setPosition(0);
            $menu->addMenuSection($section);
            $sections[$id] = $section;
        }
        foreach ([200, 100] as $id) {
            $dish = self::withId(new Product(), $id);
            $dish->setCategory($menu);
            $dish->setPosition(0);
            $dish->setMenuSection($sections[10]);
            $sections[10]->getProducts()->add($dish);
        }

        $structure = $menu->getSectionsWithProducts();

        self::assertSame([10, 20], array_map(static fn (array $e) => $e['section']->getId(), $structure));
        self::assertSame([100, 200], array_map(static fn (Product $p) => $p->getId(), $structure[0]['products']));
    }
}
