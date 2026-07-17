<?php

namespace App\Tests\Service;

use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Enum\MenuImportPageStatus;
use App\Service\MenuImportAssembler;
use App\Service\ProductAllergenResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Full-menu regression test, frozen from a real "Bar Restaurante Tropical"
 * menu (tests/fixtures/tropical.png) — a plain one-price-per-dish "menú del
 * día" list with no inline ingredient enumerations anywhere, complementing
 * ElianMenuFixtureTest's ingredient-heavy coverage. What it exercises
 * instead: rule 1b's category-level dish enumeration (this menu's PRIMEROS/
 * SEGUNDOS/POSTRES sections are printed as comma-separated dish lists, not
 * one dish per line) and rule 7b's supplement parsing across several dishes.
 *
 * tests/fixtures/tropical.vision.json is the exact, human-verified vision
 * model output for that image (confirmed byte-for-byte against the stored
 * MenuImportPage this was frozen from). tests/fixtures/tropical.expected.json
 * is the frozen correct assembler result for it. Both are static fixtures —
 * this test has no dependency on any restaurant/import data in a real
 * database; it creates its own throwaway restaurant and rolls back.
 *
 * Unlike Elian, this menu has zero ingredients printed for any dish, so no
 * Global Ingredient Library or allergen seed data is needed at all — every
 * dish's ingredients/allergens/tags are expected empty.
 */
final class TropicalMenuFixtureTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testTropicalMenuMatchesFrozenExpectation(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Test Tropical Restaurant');
        $restaurant->setSlug('test-tropical-restaurant');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $visionData = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/tropical.vision.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);

        $page = new MenuImportPage($batch, 'tropical.png', 0, hash('sha256', 'tropical'));
        $page->setStatus(MenuImportPageStatus::EXTRACTED);
        $page->setDetectedLocale($visionData['detected_language'] ?? 'es');
        $page->setExtractedData($visionData);
        $this->em->persist($page);
        $batch->addPage($page);

        $this->em->flush();

        /** @var MenuImportAssembler $assembler */
        $assembler = self::getContainer()->get(MenuImportAssembler::class);
        $assembler->assemble($batch);

        $expected = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/tropical.expected.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $products = $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch]);
        $resolver = self::getContainer()->get(ProductAllergenResolver::class);
        $allergenEntries = $resolver->resolveForProducts($products);

        // Group actual products by category, in the order the categories
        // were first encountered while iterating products by position —
        // matching how expected.json's own category order was derived.
        $byCategory = [];
        $categoryOrder = [];
        foreach ($products as $product) {
            $catId = $product->getCategory()->getId();
            if (!isset($byCategory[$catId])) {
                $byCategory[$catId] = [];
                $categoryOrder[] = $catId;
            }
            $byCategory[$catId][] = $product;
        }
        foreach ($byCategory as $catId => $catProducts) {
            usort($catProducts, fn (Product $a, Product $b) => $a->getPosition() <=> $b->getPosition());
            $byCategory[$catId] = $catProducts;
        }

        $expectedCategories = $expected['categories'];
        self::assertCount(3, $categoryOrder, 'expected exactly 3 categories: PRIMEROS, SEGUNDOS, POSTRES');
        self::assertCount(3, $expectedCategories);

        foreach ($expectedCategories as $i => $expectedCategory) {
            $catId = $categoryOrder[$i];
            $catT = $byCategory[$catId][0]->getCategory()->getTranslation('es');
            self::assertNotNull($catT, "category #{$i} must have a name — never a nameless category");
            self::assertSame($expectedCategory['name'], $catT->getName(), "category #{$i} name mismatch");

            $actualDishes = $byCategory[$catId];
            self::assertCount(
                count($expectedCategory['dishes']),
                $actualDishes,
                "category \"{$expectedCategory['name']}\" dish count mismatch"
            );

            foreach ($expectedCategory['dishes'] as $j => $expectedDish) {
                $product = $actualDishes[$j];
                $t = $product->getTranslation('es');
                self::assertNotNull($t, "dish #{$j} in \"{$expectedCategory['name']}\" has no translation");
                self::assertSame($expectedDish['name'], $t->getName(), "dish name mismatch at {$expectedCategory['name']}[{$j}]");
                self::assertSame($expectedDish['description'], $t->getDescription(), "description mismatch for \"{$expectedDish['name']}\"");
                self::assertSame((float) $expectedDish['price'], $product->getBasePriceDecimal(), "price mismatch for \"{$expectedDish['name']}\"");
                self::assertSame(
                    $expectedDish['supplementPrice'] === null ? null : (float) $expectedDish['supplementPrice'],
                    $product->getSupplementPriceDecimal(),
                    "supplementPrice mismatch for \"{$expectedDish['name']}\""
                );

                // Position-ordered merge exactly like MenuAdminController::getProduct().
                $entries = [];
                foreach ($product->getIngredientLinks() as $link) {
                    $entries[$link->getPosition()] = $link->getIngredient()->getTranslation('es')?->getName();
                }
                foreach ($product->getGlobalIngredientLinks() as $link) {
                    $entries[$link->getPosition()] = $link->getGlobalIngredient()->getTranslation('es')?->getName();
                }
                ksort($entries);
                self::assertSame($expectedDish['ingredients'], array_values($entries), "ingredients mismatch for \"{$expectedDish['name']}\"");

                $allergenCodes = array_map(
                    static fn (array $entry) => $entry['allergen']->getCode(),
                    $allergenEntries[$product->getId()] ?? []
                );
                self::assertSame($expectedDish['allergens'], $allergenCodes, "allergens mismatch for \"{$expectedDish['name']}\"");

                self::assertSame($expectedDish['recommended'], false, "recommended must default false for \"{$expectedDish['name']}\"");
                $tagCodes = array_map(static fn ($tag) => $tag->getCode(), $product->getTags()->toArray());
                self::assertSame($expectedDish['tags'], $tagCodes, "tags mismatch for \"{$expectedDish['name']}\"");
            }
        }
    }
}
