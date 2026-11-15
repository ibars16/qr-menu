<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\MenuImportBatchStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Repetido" after an AI import (Product::$importDuplicate, set by
 * MenuImportAssembler): the chip on the Carta row, the line on the import's
 * summary, never on the public menu — and gone once the owner reviews the
 * dish (publishes it, saves it from the editor) or deletes it. The dishes
 * are built as the import leaves them; no AI, no background process.
 */
final class MenuImportDuplicateReviewTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private Category $category;
    private MenuImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Duplicate review test');
        $restaurant->setSlug('dup-review-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setRestaurant($restaurant);
        $t = new CategoryTranslation();
        $t->setLocale('es');
        $t->setName('Entrantes');
        $category->addTranslation($t);
        $this->em->persist($category);
        $this->em->persist($t);

        $batch = new MenuImportBatch($restaurant);
        $batch->setStatus(MenuImportBatchStatus::READY_FOR_REVIEW);
        $this->em->persist($batch);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('dup-review-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->restaurant = $restaurant;
        $this->category = $category;
        $this->batch = $batch;
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurant->getId());
        if ($restaurant) {
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
            }
            foreach ($this->em->getRepository(MenuImportBatch::class)->findBy(['restaurant' => $restaurant]) as $batch) {
                $this->em->remove($batch);
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        parent::tearDown();
    }

    /** A dish as the import leaves it: pending review; a repeated one hidden and marked. */
    private function importedDish(string $name, bool $duplicate, bool $active = false): int
    {
        $product = new Product();
        $product->setCategory($this->em->find(Category::class, $this->category->getId()));
        $product->setBasePrice(950);
        $product->setActive($active);
        $product->setNeedsReview(true);
        $product->setImportDuplicate($duplicate);
        $product->setImportBatch($this->em->find(MenuImportBatch::class, $this->batch->getId()));
        $t = new ProductTranslation();
        $t->setLocale('es');
        $t->setName($name);
        $product->addTranslation($t);
        $this->em->persist($product);
        $this->em->persist($t);
        $this->em->flush();
        $id = $product->getId();
        $this->em->clear();

        return $id;
    }

    private function product(int $id): ?Product
    {
        $this->em->clear();

        return $this->em->find(Product::class, $id);
    }

    public function testCartaShowsTheChipOnlyOnTheRepeatedDish(): void
    {
        $original = $this->importedDish('Croquetas', duplicate: false, active: true);
        $copy = $this->importedDish('Croquetas', duplicate: true);

        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(".product-row[data-id=\"{$original}\"] .dup-badge"));
        $chip = $crawler->filter(".product-row[data-id=\"{$copy}\"] .dup-badge");
        self::assertCount(1, $chip);
        self::assertSame('Repetido', trim($chip->text()));
        self::assertStringContainsString('Ya hay un plato con este nombre en esta categoría', $chip->attr('title'));
    }

    public function testPublishingItClearsTheMark(): void
    {
        $copy = $this->importedDish('Croquetas', duplicate: true);

        $this->client->request('POST', "/admin/products/{$copy}/toggle");

        self::assertResponseIsSuccessful();
        self::assertTrue($this->product($copy)->isActive());
        self::assertFalse($this->product($copy)->isImportDuplicate());
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(0, $crawler->filter('.dup-badge'));
    }

    public function testSavingItFromTheEditorClearsTheMarkAndKeepsItHidden(): void
    {
        $copy = $this->importedDish('Croquetas', duplicate: true);

        $this->client->request(
            'POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'id' => $copy,
                'categoryId' => $this->category->getId(),
                'basePrice' => 9.5,
                'translations' => ['es' => ['name' => 'Croquetas de jamón', 'description' => null]],
            ]),
        );

        self::assertResponseIsSuccessful();
        $product = $this->product($copy);
        self::assertFalse($product->isImportDuplicate());
        self::assertFalse($product->isActive(), 'saving never changes visibility');
    }

    public function testDeletingItRemovesIt(): void
    {
        $copy = $this->importedDish('Croquetas', duplicate: true);

        $this->client->request('POST', "/admin/products/{$copy}/delete");

        self::assertResponseIsSuccessful();
        self::assertNull($this->product($copy));
    }

    public function testTheRepeatedDishNeverShowsOnThePublicMenu(): void
    {
        $original = $this->importedDish('Croquetas', duplicate: false, active: true);
        $this->importedDish('Croquetas', duplicate: true);
        $this->em->clear();
        $category = $this->em->find(Category::class, $this->category->getId());
        $category->setActive(true);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/r/' . $this->restaurant->getSlug() . '?lang=es&currency=EUR');

        self::assertResponseIsSuccessful();
        // Each dish on the public menu carries its id once (card + detail show the name twice).
        $ids = $crawler->filter('[data-product-id]')->each(fn ($n) => (int) $n->attr('data-product-id'));
        self::assertSame([$original], $ids, 'only the original is on the menu');
    }

    public function testTheSummaryListsTheRepeatedDishesApartFromTheOnesWithoutPrice(): void
    {
        $this->importedDish('Croquetas', duplicate: false, active: true);
        foreach (['Croquetas', 'Bravas', 'Gazpacho', 'Salmorejo', 'Ensaladilla', 'Pimientos', 'Calamares'] as $name) {
            $this->importedDish($name, duplicate: true);
        }
        $this->importedDish('Plato del día', duplicate: false); // hidden: no price

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $this->batch->getId() . '/complete');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('.import-complete-card')->text();
        self::assertStringContainsString('7 platos repetidos se han creado ocultos: Croquetas, Bravas, Gazpacho, Salmorejo, Ensaladilla … y 2 más.', $text);
        self::assertStringContainsString('1 plato sin precio se ha quedado oculto', $text, 'repeated dishes are not "sin precio"');
    }

    public function testOneRepeatedDishUsesTheSingular(): void
    {
        $this->importedDish('Croquetas', duplicate: false, active: true);
        $this->importedDish('Croquetas', duplicate: true);

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $this->batch->getId() . '/complete');

        $text = $crawler->filter('.import-complete-card')->text();
        self::assertStringContainsString('1 plato repetido se ha creado oculto: Croquetas.', $text);
        self::assertStringNotContainsString('sin precio', $text);
    }
}
