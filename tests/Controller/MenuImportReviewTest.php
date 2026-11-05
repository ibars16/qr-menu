<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\MenuImportBatchStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An AI-imported dish/category stops being "pending review" when the owner
 * publishes it (toggle) or saves it from the editor; deleting it counts as
 * reviewing it too. Once nothing of an import is pending, the import is
 * COMPLETED — see MenuImportReview.
 */
final class MenuImportReviewTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private Category $category;
    private MenuImportBatch $batch;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = $this->makeRestaurant();
        $this->batch = $this->makeBatch($this->restaurant);
        $this->category = $this->makeCategory($this->restaurant, null);

        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('import-review-test-' . uniqid() . '@example.test');
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
            $this->em->flush();
            foreach ($this->em->getRepository(MenuImportBatch::class)->findBy(['restaurant' => $restaurant]) as $batch) {
                $this->em->remove($batch);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function makeRestaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Import Review Test Restaurant');
        $restaurant->setSlug('import-review-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $restaurant;
    }

    private function makeBatch(Restaurant $restaurant): MenuImportBatch
    {
        $batch = new MenuImportBatch($restaurant);
        $batch->setStatus(MenuImportBatchStatus::READY_FOR_REVIEW);
        $this->em->persist($batch);
        $this->em->flush();

        return $batch;
    }

    /** A pending category is imported exactly like MenuImportAssembler leaves it: hidden, needsReview. */
    private function makeCategory(Restaurant $restaurant, ?MenuImportBatch $pendingFrom): Category
    {
        $category = new Category();
        $category->setRestaurant($restaurant);
        $category->setActive($pendingFrom === null);
        if ($pendingFrom) {
            $category->setNeedsReview(true);
            $category->setImportBatch($pendingFrom);
        }
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /** Hidden + needsReview + a confidence, exactly like MenuImportAssembler leaves it. */
    private function makePendingDish(?Category $category = null, ?MenuImportBatch $batch = null, bool $active = false): int
    {
        $product = new Product();
        $product->setCategory($category ?? $this->category);
        $product->setBasePrice(1000);
        $product->setActive($active);
        $product->setNeedsReview(true);
        $product->setAiConfidence(0.8);
        $product->setImportBatch($batch ?? $this->batch);
        $this->em->persist($product);
        $this->em->flush();

        return $product->getId();
    }

    private function post(string $url, ?array $json = null): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], $json === null ? null : json_encode($json));
        self::assertResponseIsSuccessful();
    }

    private function dish(int $id): ?Product
    {
        $this->em->clear();

        return $this->em->getRepository(Product::class)->find($id);
    }

    private function batchStatus(MenuImportBatch $batch): MenuImportBatchStatus
    {
        $this->em->clear();

        return $this->em->getRepository(MenuImportBatch::class)->find($batch->getId())->getStatus();
    }

    public function testPublishingTheLastPendingDishCompletesTheImport(): void
    {
        $id = $this->makePendingDish();

        $this->post("/admin/products/{$id}/toggle");

        $dish = $this->dish($id);
        self::assertTrue($dish->isActive());
        self::assertFalse($dish->isNeedsReview());
        self::assertNull($dish->getAiConfidence());
        self::assertSame(MenuImportBatchStatus::COMPLETED, $this->batchStatus($this->batch));
    }

    public function testTheImportStaysOpenWhileAnotherDishIsPending(): void
    {
        $published = $this->makePendingDish();
        $stillPending = $this->makePendingDish();

        $this->post("/admin/products/{$published}/toggle");

        self::assertFalse($this->dish($published)->isNeedsReview());
        self::assertTrue($this->dish($stillPending)->isNeedsReview());
        self::assertSame(MenuImportBatchStatus::READY_FOR_REVIEW, $this->batchStatus($this->batch));
    }

    public function testHidingADishIsNotReviewingIt(): void
    {
        $id = $this->makePendingDish(active: true);

        $this->post("/admin/products/{$id}/toggle");

        $dish = $this->dish($id);
        self::assertFalse($dish->isActive());
        self::assertTrue($dish->isNeedsReview());
        self::assertSame(MenuImportBatchStatus::READY_FOR_REVIEW, $this->batchStatus($this->batch));
    }

    public function testSavingFromTheEditorReviewsTheDishWithoutPublishingIt(): void
    {
        $id = $this->makePendingDish();

        $this->post('/admin/products/save', [
            'id' => $id,
            'categoryId' => $this->category->getId(),
            'basePrice' => 12.5,
            'translations' => ['es' => ['name' => 'Plato importado', 'description' => null]],
        ]);

        $dish = $this->dish($id);
        self::assertFalse($dish->isNeedsReview());
        self::assertFalse($dish->isActive(), 'the editor never changes visibility — that is the toggle\'s job');
        self::assertSame(MenuImportBatchStatus::COMPLETED, $this->batchStatus($this->batch));
    }

    public function testDeletingTheLastPendingDishCompletesTheImport(): void
    {
        $id = $this->makePendingDish();

        $this->post("/admin/products/{$id}/delete");

        self::assertNull($this->dish($id));
        self::assertSame(MenuImportBatchStatus::COMPLETED, $this->batchStatus($this->batch));
    }

    public function testAPendingCategoryKeepsTheImportOpenUntilPublished(): void
    {
        $category = $this->makeCategory($this->restaurant, $this->batch);
        $id = $this->makePendingDish($category);

        $this->post("/admin/products/{$id}/toggle");
        self::assertSame(MenuImportBatchStatus::READY_FOR_REVIEW, $this->batchStatus($this->batch), 'its category is still pending');

        $this->post("/admin/categories/{$category->getId()}/toggle");

        $this->em->clear();
        self::assertFalse($this->em->getRepository(Category::class)->find($category->getId())->isNeedsReview());
        self::assertSame(MenuImportBatchStatus::COMPLETED, $this->batchStatus($this->batch));
    }

    public function testRenamingAPendingCategoryReviewsIt(): void
    {
        $category = $this->makeCategory($this->restaurant, $this->batch);

        $this->post("/admin/categories/{$category->getId()}/edit", ['name' => 'Entrantes']);

        $this->em->clear();
        self::assertFalse($this->em->getRepository(Category::class)->find($category->getId())->isNeedsReview());
        self::assertSame(MenuImportBatchStatus::COMPLETED, $this->batchStatus($this->batch));
    }

    public function testDeletingAPendingCategoryWithItsDishesCompletesTheImport(): void
    {
        $category = $this->makeCategory($this->restaurant, $this->batch);
        $this->makePendingDish($category);
        $this->makePendingDish($category);
        // The request must load the category fresh, with its dishes — not
        // reuse this test's copy whose (never filled) collection would
        // cascade-delete nothing.
        $this->em->clear();

        $this->post("/admin/categories/{$category->getId()}/delete");

        self::assertSame(MenuImportBatchStatus::COMPLETED, $this->batchStatus($this->batch));
    }

    public function testAnotherRestaurantsImportIsNeverTouched(): void
    {
        $other = $this->makeRestaurant();
        $otherBatch = $this->makeBatch($other); // nothing pending — would be completed if it were ours
        $id = $this->makePendingDish();

        $this->post("/admin/products/{$id}/toggle");

        self::assertSame(MenuImportBatchStatus::COMPLETED, $this->batchStatus($this->batch));
        self::assertSame(MenuImportBatchStatus::READY_FOR_REVIEW, $this->batchStatus($otherBatch));
    }

    public function testAnImportStillBeingReadIsNeverCompleted(): void
    {
        $reading = new MenuImportBatch($this->restaurant);
        $reading->setStatus(MenuImportBatchStatus::PROCESSING);
        $this->em->persist($reading);
        $this->em->flush();
        $id = $this->makePendingDish();

        $this->post("/admin/products/{$id}/toggle");

        self::assertSame(MenuImportBatchStatus::PROCESSING, $this->batchStatus($reading));
    }
}
