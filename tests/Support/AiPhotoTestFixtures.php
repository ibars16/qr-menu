<?php

namespace App\Tests\Support;

use App\Entity\Category;
use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\MenuImportBatchStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Shared setup for the AI dish photo WebTestCases: restaurants, owners,
 * dishes, imports — and the cleanup of everything they create, files
 * included (var/test-uploads, var/test-ai_photo_drafts; never public/).
 * The provider is always FakeDishImageGenerator (services.yaml when@test).
 */
trait AiPhotoTestFixtures
{
    use TestUploadsDir;

    private EntityManagerInterface $em;

    /** @var int[] */
    private array $restaurantIds = [];

    /** @var array{env: ?string, server: ?string}|null GEMINI_IMAGE_API_KEY before withoutImageKey() */
    private ?array $savedImageKey = null;

    /**
     * As if .env.local had no GEMINI_IMAGE_API_KEY (the text key stays set).
     * The client reboots its kernel per request, so the next one sees it.
     */
    private function withoutImageKey(): void
    {
        $this->savedImageKey ??= ['env' => $_ENV['GEMINI_IMAGE_API_KEY'] ?? null, 'server' => $_SERVER['GEMINI_IMAGE_API_KEY'] ?? null];
        $_ENV['GEMINI_IMAGE_API_KEY'] = '';
        $_SERVER['GEMINI_IMAGE_API_KEY'] = '';
    }

    private function setUpAiPhoto(): void
    {
        FakeDishImageGenerator::reset();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::testUploadsDir('products');
    }

    private function tearDownAiPhoto(): void
    {
        FakeDishImageGenerator::reset();
        if ($this->savedImageKey !== null) {
            foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
                if ($this->savedImageKey[$key] === null) {
                    unset($GLOBALS[$global]['GEMINI_IMAGE_API_KEY']);
                } else {
                    $GLOBALS[$global]['GEMINI_IMAGE_API_KEY'] = $this->savedImageKey[$key];
                }
            }
            $this->savedImageKey = null;
        }
        $this->em->clear();
        $conn = $this->em->getConnection();
        foreach ($this->restaurantIds as $id) {
            $restaurant = $this->em->find(Restaurant::class, $id);
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
                $this->em->flush();
                $this->em->remove($category);
            }
            $this->em->flush();
            $conn->executeStatement('DELETE FROM menu_import_batch WHERE restaurant_id = ' . (int) $id);
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        self::emptyTestUploadsDir();
        $drafts = static::getContainer()->getParameter('app.ai_photo_drafts_dir');
        if (str_contains($drafts, '/var/test-') && is_dir($drafts)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($drafts, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
    }

    /**
     * Not paid yet but a card is set: what coming back from Checkout during
     * the trial leaves (plan and subscription set, Stripe has charged 0 €).
     */
    private function setCardPending(Restaurant $restaurant): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE restaurant SET plan = 'monthly', stripe_subscription_id = ? WHERE id = ?",
            ['sub_test_' . uniqid(), $restaurant->getId()],
        );
        $this->em->refresh($restaurant);
    }

    /** This month's DishPhotoQuota::MONTHLY_LIMIT already used. */
    private function useUpMonth(Restaurant $restaurant, ?string $month = null): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE restaurant SET ai_photo_month = ?, ai_photo_month_count = ? WHERE id = ?',
            [$month ?? date('Y-m-01'), \App\Service\DishPhoto\DishPhotoQuota::MONTHLY_LIMIT, $restaurant->getId()],
        );
    }

    /** The monthly limit's message as the Spanish panel says it this month. */
    private static function monthlyLimitMessage(): string
    {
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $next = (int) (new \DateTimeImmutable('first day of next month'))->format('n');

        return 'Has llegado al límite de fotos con IA de este mes. Se renueva el 1 de ' . $months[$next - 1] . '.';
    }

    /** A restaurant on its free trial (never paid), or one that has paid with $paid. */
    private function restaurant(bool $paid = false): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('AI Photo Test');
        $restaurant->setSlug('dish-pic-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        if ($paid) {
            $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        }
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIds[] = $restaurant->getId();

        return $restaurant;
    }

    private function owner(Restaurant $restaurant): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('dish-pic-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function category(Restaurant $restaurant): Category
    {
        $category = new Category();
        $category->setRestaurant($restaurant);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function dish(Category $category, string $name, ?string $image = null, ?MenuImportBatch $batch = null, ?string $description = null): Product
    {
        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice(1000);
        $product->setImage($image);
        $product->setImportBatch($batch);
        $translation = new ProductTranslation();
        $translation->setProduct($product);
        $translation->setLocale('es');
        $translation->setName($name);
        $translation->setDescription($description);
        $product->addTranslation($translation);
        $this->em->persist($product);
        $this->em->persist($translation);
        $this->em->flush();

        return $product;
    }

    private function importBatch(Restaurant $restaurant): MenuImportBatch
    {
        $batch = new MenuImportBatch($restaurant);
        $batch->setStatus(MenuImportBatchStatus::READY_FOR_REVIEW);
        $this->em->persist($batch);
        $this->em->flush();

        return $batch;
    }

    private function productsDir(): string
    {
        return self::testUploadsDir('products') . '/products';
    }

    private function aiPhotoCount(Restaurant $restaurant): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT ai_photo_count FROM restaurant WHERE id = ?', [$restaurant->getId()]);
    }

    private function reload(Product $product): Product
    {
        $this->em->clear();

        return $this->em->find(Product::class, $product->getId());
    }
}
