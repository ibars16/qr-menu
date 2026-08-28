<?php

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductView;
use App\Entity\Restaurant;
use App\Repository\ProductViewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Confirms topProducts() ordering/limit and viewsPerDay() date-range
 * exclusion — the two aggregate queries the admin stats page (see
 * StatsController) is built on.
 *
 * Restaurants created here are removed in tearDown() rather than relying on
 * a transactional rollback (none is configured for this suite). Categories
 * and Products are queried back and removed explicitly first — mirrors
 * SetMenusFeatureGateTest's tearDown, since Category/Product were only ever
 * attached via the owning side (setRestaurant/setCategory) here, so
 * Restaurant's own OneToMany collection never picked them up for cascade
 * removal. ProductView's product_id FK is ON DELETE CASCADE at the database
 * level, so no ProductView row needs removing by hand.
 */
final class ProductViewRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProductViewRepository $repo;
    private Restaurant $restaurant;
    private Category $category;

    /** @var Restaurant[] */
    private array $restaurantsToRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(ProductViewRepository::class);

        $this->restaurant = $this->makeRestaurant('Product View Test Restaurant');

        $this->category = new Category();
        $this->category->setRestaurant($this->restaurant);
        $this->em->persist($this->category);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantsToRemove as $restaurant) {
            $categories = $this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]);
            foreach ($categories as $category) {
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

    private function makeRestaurant(string $name): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setSlug('product-view-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->restaurantsToRemove[] = $restaurant;

        return $restaurant;
    }

    private function makeProduct(Category $category, int $basePrice = 1000): Product
    {
        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice($basePrice);
        $this->em->persist($product);

        return $product;
    }

    private function recordView(Restaurant $restaurant, Product $product, \DateTimeImmutable $at): void
    {
        $view = new ProductView($restaurant, $product);
        (new \ReflectionProperty(ProductView::class, 'createdAt'))->setValue($view, $at);
        $this->em->persist($view);
    }

    public function testTopProductsOrdersByViewCountDescendingAndRespectsLimit(): void
    {
        $popular = $this->makeProduct($this->category);
        $middling = $this->makeProduct($this->category, 1200);
        $rare = $this->makeProduct($this->category, 900);

        $now = new \DateTimeImmutable();
        foreach (range(1, 5) as $_) {
            $this->recordView($this->restaurant, $popular, $now);
        }
        foreach (range(1, 2) as $_) {
            $this->recordView($this->restaurant, $middling, $now);
        }
        $this->recordView($this->restaurant, $rare, $now);
        $this->em->flush();

        $top = $this->repo->topProducts($this->restaurant, $now->modify('-1 day'), 2);

        self::assertCount(2, $top, 'limit=2 must cap the result set');
        self::assertSame($popular->getId(), $top[0]['product']->getId());
        self::assertSame(5, $top[0]['views']);
        self::assertSame($middling->getId(), $top[1]['product']->getId());
        self::assertSame(2, $top[1]['views']);
    }

    public function testViewsPerDayExcludesRowsBeforeSince(): void
    {
        $product = $this->makeProduct($this->category);

        $today = new \DateTimeImmutable('today');
        $this->recordView($this->restaurant, $product, $today);
        $this->recordView($this->restaurant, $product, $today);
        $this->recordView($this->restaurant, $product, $today->modify('-10 days'));
        $this->em->flush();

        $perDay = $this->repo->viewsPerDay($this->restaurant, $today->modify('-3 days'));

        self::assertCount(1, $perDay, 'the 10-day-old view must fall outside the 3-day window');
        self::assertSame($today->format('Y-m-d'), $perDay[0]['day']);
        self::assertSame(2, $perDay[0]['views']);
    }

    public function testTotalViewsCountsOnlyThisRestaurantSinceTheGivenDate(): void
    {
        $otherRestaurant = $this->makeRestaurant('Other Restaurant');
        $otherCategory = new Category();
        $otherCategory->setRestaurant($otherRestaurant);
        $this->em->persist($otherCategory);

        $product = $this->makeProduct($this->category);
        $otherProduct = $this->makeProduct($otherCategory);

        $now = new \DateTimeImmutable();
        $this->recordView($this->restaurant, $product, $now);
        $this->recordView($this->restaurant, $product, $now);
        $this->recordView($otherRestaurant, $otherProduct, $now);
        $this->em->flush();

        self::assertSame(2, $this->repo->totalViews($this->restaurant, $now->modify('-1 day')));
    }
}
