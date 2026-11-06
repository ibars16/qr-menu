<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Carta's "Ordenar" mode (POST /admin/reorder/categories and
 * /admin/reorder/products, see CartaReorder): the whole list is saved as
 * 0..n-1, hidden and pending-review rows included and left pending; a list
 * that is missing a row, repeats one, mixes categories or names another
 * restaurant's rows is refused and nothing moves. Staff can reorder too.
 */
final class CartaReorderTest extends WebTestCase
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
        $this->em->clear();
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

    private function restaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Carta Reorder Test');
        $restaurant->setSlug('carta-reorder-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $restaurant;
    }

    private function login(Restaurant $restaurant, string $role = User::ROLE_OWNER): void
    {
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('carta-reorder-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([$role]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    private function category(Restaurant $restaurant, int $position, bool $active = true, bool $needsReview = false, ?int $menuPrice = null): Category
    {
        $category = new Category();
        $category->setRestaurant($restaurant);
        $category->setPosition($position);
        $category->setActive($active);
        $category->setNeedsReview($needsReview);
        $category->setMenuPrice($menuPrice);
        $restaurant->addCategory($category);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function product(Category $category, int $position, bool $active = true, bool $needsReview = false): Product
    {
        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice(1000);
        $product->setPosition($position);
        $product->setActive($active);
        $product->setNeedsReview($needsReview);
        $category->addProduct($product);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body): int
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));

        return $this->client->getResponse()->getStatusCode();
    }

    /** @param int[] $ids @return array<int, int> id => position, read fresh from the database */
    private function categoryPositions(array $ids): array
    {
        $this->em->clear();
        $positions = [];
        foreach ($ids as $id) {
            $positions[$id] = $this->em->getRepository(Category::class)->find($id)->getPosition();
        }

        return $positions;
    }

    /** @param int[] $ids @return array<int, int> */
    private function productPositions(array $ids): array
    {
        $this->em->clear();
        $positions = [];
        foreach ($ids as $id) {
            $positions[$id] = $this->em->getRepository(Product::class)->find($id)->getPosition();
        }

        return $positions;
    }

    // ── Categories ──────────────────────────────────────────────────────────

    public function testCategoriesAreSavedInTheSentOrderIncludingHiddenAndPending(): void
    {
        $restaurant = $this->restaurant();
        $a = $this->category($restaurant, 0)->getId();
        $hidden = $this->category($restaurant, 1, active: false)->getId();
        $pending = $this->category($restaurant, 2, needsReview: true)->getId();
        // A fixed-price menu has its own order (Menús screen): not part of the list, not touched.
        $menu = $this->category($restaurant, 0, menuPrice: 1500)->getId();
        $this->login($restaurant);

        self::assertSame(200, $this->post('/admin/reorder/categories', ['ids' => [$pending, $a, $hidden]]));

        self::assertSame([$pending => 0, $a => 1, $hidden => 2, $menu => 0], $this->categoryPositions([$pending, $a, $hidden, $menu]));
        self::assertTrue($this->em->getRepository(Category::class)->find($pending)->isNeedsReview(), 'ordering is not reviewing');
    }

    public function testStaffCanReorderCategories(): void
    {
        $restaurant = $this->restaurant();
        $a = $this->category($restaurant, 0)->getId();
        $b = $this->category($restaurant, 1)->getId();
        $this->login($restaurant, User::ROLE_STAFF);

        self::assertSame(200, $this->post('/admin/reorder/categories', ['ids' => [$b, $a]]));
        self::assertSame([$b => 0, $a => 1], $this->categoryPositions([$b, $a]));
    }

    public function testCategoryListMissingOneIsRejected(): void
    {
        $restaurant = $this->restaurant();
        $a = $this->category($restaurant, 0)->getId();
        $b = $this->category($restaurant, 1)->getId();
        $c = $this->category($restaurant, 2)->getId();
        $this->login($restaurant);

        self::assertSame(400, $this->post('/admin/reorder/categories', ['ids' => [$c, $a]]));
        self::assertSame([$a => 0, $b => 1, $c => 2], $this->categoryPositions([$a, $b, $c]));
    }

    public function testCategoryListWithADuplicateIsRejected(): void
    {
        $restaurant = $this->restaurant();
        $a = $this->category($restaurant, 0)->getId();
        $b = $this->category($restaurant, 1)->getId();
        $this->login($restaurant);

        self::assertSame(400, $this->post('/admin/reorder/categories', ['ids' => [$b, $b]]));
        self::assertSame([$a => 0, $b => 1], $this->categoryPositions([$a, $b]));
    }

    public function testCategoryListIncludingAMenuIsRejected(): void
    {
        $restaurant = $this->restaurant();
        $a = $this->category($restaurant, 0)->getId();
        $menu = $this->category($restaurant, 1, menuPrice: 1500)->getId();
        $this->login($restaurant);

        self::assertSame(400, $this->post('/admin/reorder/categories', ['ids' => [$menu, $a]]));
        self::assertSame([$a => 0, $menu => 1], $this->categoryPositions([$a, $menu]));
    }

    public function testAnotherRestaurantsCategoriesCannotBeReordered(): void
    {
        $victim = $this->restaurant();
        $a = $this->category($victim, 0)->getId();
        $b = $this->category($victim, 1)->getId();
        $attacker = $this->restaurant();
        $this->login($attacker);

        self::assertSame(400, $this->post('/admin/reorder/categories', ['ids' => [$b, $a]]));
        self::assertSame([$a => 0, $b => 1], $this->categoryPositions([$a, $b]));
    }

    public function testTheOldCategoriesScreenEndpointFollowsTheSameRule(): void
    {
        $restaurant = $this->restaurant();
        $a = $this->category($restaurant, 0)->getId();
        $b = $this->category($restaurant, 1)->getId();
        $c = $this->category($restaurant, 2)->getId();
        $this->login($restaurant);

        self::assertSame(400, $this->post('/admin/categories/reorder', ['ids' => [$c, $a]]));
        self::assertSame(200, $this->post('/admin/categories/reorder', ['ids' => [$c, $a, $b]]));
        self::assertSame([$c => 0, $a => 1, $b => 2], $this->categoryPositions([$c, $a, $b]));
    }

    // ── Dishes ──────────────────────────────────────────────────────────────

    public function testDishesAreSavedInTheSentOrderIncludingHiddenAndPending(): void
    {
        $restaurant = $this->restaurant();
        $category = $this->category($restaurant, 0);
        $a = $this->product($category, 0)->getId();
        $hidden = $this->product($category, 1, active: false)->getId();
        $pending = $this->product($category, 2, needsReview: true)->getId();
        $categoryId = $category->getId();
        $this->login($restaurant);

        self::assertSame(200, $this->post('/admin/reorder/products', ['categoryId' => $categoryId, 'ids' => [$hidden, $pending, $a]]));

        self::assertSame([$hidden => 0, $pending => 1, $a => 2], $this->productPositions([$hidden, $pending, $a]));
        self::assertTrue($this->em->getRepository(Product::class)->find($pending)->isNeedsReview(), 'ordering is not reviewing');
    }

    public function testStaffCanReorderDishes(): void
    {
        $restaurant = $this->restaurant();
        $category = $this->category($restaurant, 0);
        $a = $this->product($category, 0)->getId();
        $b = $this->product($category, 1)->getId();
        $categoryId = $category->getId();
        $this->login($restaurant, User::ROLE_STAFF);

        self::assertSame(200, $this->post('/admin/reorder/products', ['categoryId' => $categoryId, 'ids' => [$b, $a]]));
        self::assertSame([$b => 0, $a => 1], $this->productPositions([$b, $a]));
    }

    public function testDishListMixingCategoriesIsRejected(): void
    {
        $restaurant = $this->restaurant();
        $first = $this->category($restaurant, 0);
        $second = $this->category($restaurant, 1);
        $a = $this->product($first, 0)->getId();
        $b = $this->product($first, 1)->getId();
        $other = $this->product($second, 0)->getId();
        $firstId = $first->getId();
        $this->login($restaurant);

        // Same size as the category's list, one id swapped for another category's dish.
        self::assertSame(400, $this->post('/admin/reorder/products', ['categoryId' => $firstId, 'ids' => [$other, $a]]));
        // All of it plus a stranger.
        self::assertSame(400, $this->post('/admin/reorder/products', ['categoryId' => $firstId, 'ids' => [$b, $a, $other]]));

        self::assertSame([$a => 0, $b => 1, $other => 0], $this->productPositions([$a, $b, $other]));
    }

    public function testDishListMissingOneIsRejected(): void
    {
        $restaurant = $this->restaurant();
        $category = $this->category($restaurant, 0);
        $a = $this->product($category, 0)->getId();
        $b = $this->product($category, 1)->getId();
        $c = $this->product($category, 2)->getId();
        $categoryId = $category->getId();
        $this->login($restaurant);

        self::assertSame(400, $this->post('/admin/reorder/products', ['categoryId' => $categoryId, 'ids' => [$c, $a]]));
        self::assertSame(400, $this->post('/admin/reorder/products', ['ids' => [$c, $b, $a]]), 'the category is required');
        self::assertSame([$a => 0, $b => 1, $c => 2], $this->productPositions([$a, $b, $c]));
    }

    public function testAnotherRestaurantsDishesCannotBeReordered(): void
    {
        $victim = $this->restaurant();
        $category = $this->category($victim, 0);
        $a = $this->product($category, 0)->getId();
        $b = $this->product($category, 1)->getId();
        $categoryId = $category->getId();
        $attacker = $this->restaurant();
        $this->login($attacker);

        self::assertSame(400, $this->post('/admin/reorder/products', ['categoryId' => $categoryId, 'ids' => [$b, $a]]));
        self::assertSame([$a => 0, $b => 1], $this->productPositions([$a, $b]));
    }
}
