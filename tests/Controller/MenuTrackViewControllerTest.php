<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductView;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional coverage for MenuController::trackView (POST /r/{slug}/view).
 * The one behavior most worth pinning down here is ownership: a
 * client-supplied productId must never let one restaurant's traffic write a
 * ProductView against another restaurant's product.
 */
final class MenuTrackViewControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private Category $category;
    private Product $product;

    /** @var Restaurant[] */
    private array $restaurantsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = $this->makeRestaurant('Track View Test Restaurant');

        $this->category = new Category();
        $this->category->setRestaurant($this->restaurant);
        $this->em->persist($this->category);

        $this->product = new Product();
        $this->product->setCategory($this->category);
        $this->product->setBasePrice(1000);
        $this->em->persist($this->product);

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
        $restaurant->setSlug('track-view-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->restaurantsToRemove[] = $restaurant;

        return $restaurant;
    }

    private function countViewsFor(Product $product): int
    {
        $repo = $this->em->getRepository(ProductView::class);

        return count($repo->findBy(['product' => $product]));
    }

    public function testValidProductIdPersistsAProductViewAndReturnsOk(): void
    {
        $this->client->request(
            'POST',
            '/r/' . $this->restaurant->getSlug() . '/view',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['productId' => $this->product->getId()])
        );

        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
        self::assertSame(1, $this->countViewsFor($this->product));
    }

    public function testProductBelongingToAnotherRestaurantIsRejected(): void
    {
        $otherRestaurant = $this->makeRestaurant('Other Restaurant');
        $otherCategory = new Category();
        $otherCategory->setRestaurant($otherRestaurant);
        $this->em->persist($otherCategory);

        $otherProduct = new Product();
        $otherProduct->setCategory($otherCategory);
        $otherProduct->setBasePrice(1000);
        $this->em->persist($otherProduct);
        $this->em->flush();

        // Posting to $this->restaurant's own track-view URL but naming a
        // product that belongs to $otherRestaurant's category.
        $this->client->request(
            'POST',
            '/r/' . $this->restaurant->getSlug() . '/view',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['productId' => $otherProduct->getId()])
        );

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 404]);
        self::assertSame(0, $this->countViewsFor($otherProduct));
    }

    public function testUnknownRestaurantSlugReturns404(): void
    {
        $this->client->request(
            'POST',
            '/r/does-not-exist-' . uniqid() . '/view',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['productId' => $this->product->getId()])
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
