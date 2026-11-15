<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\MenuImportPageStatus;
use App\Service\MenuImportAssembler;
use App\Service\MenuImportReview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Platos repetidos" in the AI import (MenuImportAssembler): a dish whose
 * name — lowercase, Latin accents and extra spaces ignored — is already in
 * its category, in the carta or earlier in the same upload, is still
 * created but hidden and marked importDuplicate. Reviewing it
 * (MenuImportReview::confirm()) clears the mark. Works on already-extracted
 * page JSON: no AI anywhere.
 */
final class MenuImportDuplicateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MenuImportAssembler $assembler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->assembler = self::getContainer()->get(MenuImportAssembler::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    private function restaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Duplicate test');
        $restaurant->setSlug('dup-test-' . uniqid());
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();

        return $restaurant;
    }

    /** A hand-made dish already in the carta, with optional extra translations (locale => name). */
    private function existingDish(Restaurant $restaurant, string $categoryName, string $name, array $otherNames = []): void
    {
        $category = $this->em->getRepository(CategoryTranslation::class)->findOneBy(['name' => $categoryName])?->getCategory();
        if (!$category || $category->getRestaurant() !== $restaurant) {
            $category = new Category();
            $category->setRestaurant($restaurant);
            $t = new CategoryTranslation();
            $t->setLocale('es');
            $t->setName($categoryName);
            $category->addTranslation($t);
            $this->em->persist($category);
            $this->em->persist($t);
        }

        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice(900);
        foreach (['es' => $name] + $otherNames as $locale => $translated) {
            $t = new ProductTranslation();
            $t->setLocale($locale);
            $t->setName($translated);
            $product->addTranslation($t);
            $this->em->persist($t);
        }
        $this->em->persist($product);
        $this->em->flush();
    }

    private static function dish(string $name, ?float $price = 9.5): array
    {
        return [
            'name' => $name, 'name_uncertain' => false, 'description' => null, 'description_uncertain' => false,
            'price' => $price, 'price_uncertain' => false, 'supplement_price' => null, 'recommended' => false,
            'ingredients' => [], 'dietary_markers' => [],
        ];
    }

    /**
     * @param list<array<string, list<array>>> $pages each page: category name => dishes
     * @return list<Product> this import's dishes, in creation order
     */
    private function import(Restaurant $restaurant, array $pages): array
    {
        // A fresh read, as in production (the import runs in its own process).
        $restaurantId = $restaurant->getId();
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $restaurantId);

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);
        foreach ($pages as $position => $categories) {
            $page = new MenuImportPage($batch, 'test.jpg', $position, hash('sha256', uniqid()));
            $page->setStatus(MenuImportPageStatus::EXTRACTED);
            $page->setExtractedData(['detected_language' => 'es', 'categories' => array_map(
                static fn (string $name, array $products) => ['name' => $name, 'products' => $products],
                array_keys($categories),
                $categories,
            )]);
            $this->em->persist($page);
            $batch->addPage($page);
        }
        $this->em->flush();

        $this->assembler->assemble($batch);

        return $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch], ['id' => 'ASC']);
    }

    /** @param list<Product> $products @return array<string, bool> name => is duplicate */
    private static function marks(array $products): array
    {
        $marks = [];
        foreach ($products as $product) {
            $marks[$product->getTranslation('es')->getName()] = $product->isImportDuplicate();
        }

        return $marks;
    }

    public function testTheSamePageSentTwiceCreatesTheSecondCopyHiddenAndMarked(): void
    {
        $restaurant = $this->restaurant();
        $page = ['Entrantes' => [self::dish('Croquetas'), self::dish('Bravas')]];

        $products = $this->import($restaurant, [$page, $page]);

        self::assertCount(4, $products, 'the repeated dishes are still created');
        [$croquetas, $bravas, $croquetas2, $bravas2] = $products;
        // First carta: the first copy is published as before.
        self::assertTrue($croquetas->isActive());
        self::assertFalse($croquetas->isImportDuplicate());
        self::assertTrue($bravas->isActive());
        foreach ([$croquetas2, $bravas2] as $copy) {
            self::assertTrue($copy->isImportDuplicate());
            self::assertFalse($copy->isActive(), 'a repeated dish is hidden even on a first carta');
            self::assertTrue($copy->isNeedsReview());
            self::assertSame($croquetas->getCategory(), $copy->getCategory());
        }
    }

    public function testADishAlreadyInTheCartaMatchesIgnoringCaseAccentsAndSpaces(): void
    {
        $restaurant = $this->restaurant();
        $this->existingDish($restaurant, 'Postres', 'Tarta de queso');
        $this->existingDish($restaurant, 'Postres', 'Flan casero');

        $products = $this->import($restaurant, [['Postres' => [
            self::dish('  TARTA   de  Quéso '),
            self::dish('Flan  Casero', 4.0), // different price: still repeated
            self::dish('Natillas'),
        ]]]);

        self::assertSame(['TARTA   de  Quéso' => true, 'Flan  Casero' => true, 'Natillas' => false], self::marks($products));
        foreach ($products as $product) {
            self::assertFalse($product->isActive(), 'a later import is hidden anyway');
        }
    }

    public function testTheSameNameInAnotherCategoryIsNotRepeated(): void
    {
        $restaurant = $this->restaurant();
        $this->existingDish($restaurant, 'Entrantes', 'Ensalada de la casa');

        $products = $this->import($restaurant, [['Principales' => [self::dish('Ensalada de la casa')]]]);

        self::assertFalse($products[0]->isImportDuplicate());
    }

    public function testAnExistingDishMatchesInAnyOfItsLanguages(): void
    {
        $restaurant = $this->restaurant();
        $this->existingDish($restaurant, 'Entrantes', 'Croquetas', ['en' => 'Croquettes']);

        $products = $this->import($restaurant, [['Entrantes' => [self::dish('croquettes')]]]);

        self::assertTrue($products[0]->isImportDuplicate());
    }

    public function testDifferentNonLatinNamesAreNotRepeated(): void
    {
        $restaurant = $this->restaurant();

        $products = $this->import($restaurant, [['المقبلات' => [
            self::dish('حمص'),
            self::dish('فلافل'),
            self::dish('饺子'),
            self::dish('包子'),
            self::dish('حمص'),
        ]]]);

        self::assertSame([false, false, false, false, true], array_map(fn (Product $p) => $p->isImportDuplicate(), $products));
    }

    public function testNameNormalisation(): void
    {
        self::assertSame('tarta de queso', MenuImportAssembler::duplicateKey("  Tárta \t de   QUESO "));
        self::assertSame('pina colada', MenuImportAssembler::duplicateKey('Piña Colada'));
        self::assertSame('creme brulee', MenuImportAssembler::duplicateKey('Crème Brûlée'));
        self::assertSame('حمص', MenuImportAssembler::duplicateKey('حمص'));
        self::assertSame('', MenuImportAssembler::duplicateKey('   '));
    }

    public function testReviewingClearsTheMark(): void
    {
        $restaurant = $this->restaurant();
        $page = ['Entrantes' => [self::dish('Croquetas')]];
        $copy = $this->import($restaurant, [$page, $page])[1];
        self::assertTrue($copy->isImportDuplicate());

        self::getContainer()->get(MenuImportReview::class)->confirm($copy);

        self::assertFalse($copy->isImportDuplicate());
        self::assertFalse($copy->isNeedsReview());
    }
}
