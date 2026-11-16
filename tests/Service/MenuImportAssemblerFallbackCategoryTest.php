<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Enum\MenuImportPageStatus;
use App\Service\MenuImportAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A category the AI returned with an empty name: its dishes are no longer
 * dropped — they go to "Otros" in the carta's main language, reusing one
 * the carta already has, with the import's usual visibility and
 * repeated-dish rules, and counted like any other dish. Fake extraction
 * JSON only: no AI call.
 */
final class MenuImportAssemblerFallbackCategoryTest extends KernelTestCase
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

    private function restaurant(string $language): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Fallback Category Test');
        $restaurant->setSlug('test-fallback-category-' . uniqid());
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage($language);
        $this->em->persist($restaurant);

        return $restaurant;
    }

    private static function dish(string $name, ?float $price): array
    {
        return [
            'name' => $name, 'name_uncertain' => false, 'description' => null, 'description_uncertain' => false,
            'price' => $price, 'price_uncertain' => false, 'supplement_price' => null, 'recommended' => false,
            'ingredients' => [], 'dietary_markers' => [],
        ];
    }

    /** @param list<array{name?: mixed, products: list<array>}> $categories */
    private function batch(Restaurant $restaurant, array $categories): MenuImportBatch
    {
        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);
        $page = new MenuImportPage($batch, 'test.jpg', 0, hash('sha256', uniqid()));
        $page->setStatus(MenuImportPageStatus::EXTRACTED);
        $page->setDetectedLocale($restaurant->getDefaultLanguage());
        $page->setExtractedData(['detected_language' => $restaurant->getDefaultLanguage(), 'categories' => $categories]);
        $this->em->persist($page);
        $batch->addPage($page);
        $this->em->flush();

        return $batch;
    }

    /** @return list<Product> */
    private function products(MenuImportBatch $batch): array
    {
        return $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch], ['id' => 'ASC']);
    }

    /** @return list<Category> */
    private function categories(Restaurant $restaurant): array
    {
        return $this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant], ['id' => 'ASC']);
    }

    public function testDishesOfANamelessCategoryGoToOtros(): void
    {
        $restaurant = $this->restaurant('es');
        $batch = $this->batch($restaurant, [
            ['name' => 'Primeros', 'products' => [self::dish('Sopa', 8.5)]],
            ['name' => '', 'products' => [self::dish('Croquetas', 9.0), self::dish('Plato del día', null)]],
            ['name' => '   ', 'products' => [self::dish('Pan', 1.5)]],
            ['products' => [self::dish('Aceitunas', 3.0)]],
            ['name' => '', 'products' => []],
        ]);

        $result = $this->assembler->assemble($batch);

        self::assertSame(5, $result->productsCreated, 'every dish counts, none dropped');
        self::assertSame(0, $result->productsSkipped);
        self::assertSame(2, $result->categoriesCreated, 'Primeros + one Otros (no empty one for the dish-less entry)');

        $categories = $this->categories($restaurant);
        self::assertCount(2, $categories);
        $otros = $categories[1];
        self::assertSame('Otros', $otros->getTranslation('es')?->getName());
        self::assertSame(['Croquetas', 'Plato del día', 'Pan', 'Aceitunas'], array_map(
            static fn (Product $p) => $p->getTranslation('es')?->getName(),
            $otros->getProducts()->toArray(),
        ));

        // First carta: published like the rest — except the dish without a price.
        self::assertTrue($otros->isActive());
        self::assertTrue($otros->isNeedsReview());
        foreach ($otros->getProducts() as $product) {
            self::assertSame($product->getTranslation('es')?->getName() !== 'Plato del día', $product->isActive());
            self::assertTrue($product->isNeedsReview());
            self::assertFalse($product->isImportDuplicate());
        }
    }

    public function testExistingOtrosIsReused(): void
    {
        $restaurant = $this->restaurant('es');
        $first = $this->batch($restaurant, [
            ['name' => 'Otros', 'products' => [self::dish('Croquetas', 9.0)]],
        ]);
        $this->assembler->assemble($first);
        [$otros] = $this->categories($restaurant);

        // A later import: hidden, and a name already in "Otros" is a repeat.
        $second = $this->batch($restaurant, [
            ['name' => '', 'products' => [self::dish('Croquetas', 9.0), self::dish('Gazpacho', 6.0)]],
        ]);
        $result = $this->assembler->assemble($second);

        self::assertSame(0, $result->categoriesCreated);
        self::assertSame(1, $result->categoriesReused);
        self::assertSame(2, $result->productsCreated);
        self::assertCount(1, $this->categories($restaurant), 'no second Otros');

        $products = $this->products($second);
        self::assertCount(2, $products);
        foreach ($products as $product) {
            self::assertSame($otros, $product->getCategory());
            self::assertFalse($product->isActive(), 'later import: hidden until reviewed');
        }
        self::assertTrue($products[0]->isImportDuplicate(), 'Croquetas was already in Otros');
        self::assertFalse($products[1]->isImportDuplicate());
    }

    public function testOtrosIsInTheCartasMainLanguage(): void
    {
        foreach (['en' => 'Other', 'de' => 'Sonstiges', 'ja' => 'その他', 'ar' => 'أخرى'] as $language => $expected) {
            $restaurant = $this->restaurant($language);
            $batch = $this->batch($restaurant, [
                ['name' => '', 'products' => [self::dish('Dish ' . $language, 5.0)]],
            ]);

            $result = $this->assembler->assemble($batch);

            self::assertSame(1, $result->productsCreated, $language);
            [$category] = $this->categories($restaurant);
            self::assertCount(1, $category->getTranslations(), $language);
            self::assertSame($expected, $category->getTranslation($language)?->getName(), $language);
            self::assertNull($category->getTranslation('es'), $language . ': not tagged as Spanish');
        }
    }

    /** Every content language (config/languages.php) has its own "Otros". */
    public function testFallbackNameExistsInAllTwentyLanguages(): void
    {
        $languages = array_keys(require self::getContainer()->getParameter('kernel.project_dir') . '/config/languages.php');
        self::assertCount(20, $languages);
        $translator = self::getContainer()->get('translator');

        $names = [];
        foreach ($languages as $language) {
            $catalogue = $translator->getCatalogue($language);
            self::assertTrue($catalogue->defines('category.fallback_name', 'menu_public'), $language);
            $names[$language] = $catalogue->get('category.fallback_name', 'menu_public');
        }
        self::assertSame('Otros', $names['es']);
    }
}
