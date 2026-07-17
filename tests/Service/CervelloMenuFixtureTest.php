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
 * Full-menu regression test, frozen from a real, MULTI-PAGE, bilingual
 * (Catalan primary / Spanish translation) "Restaurant Cal Cervelló" menu
 * (tests/fixtures/cervello.png + cervello1/2/3.png — 4 pages, one
 * MenuImportPage each), complementing Elian (single page, ingredient-heavy)
 * and Tropical (single page, category-enumeration-heavy). What this one
 * exercises instead: MenuVisionPromptBuilder rule 1c (bilingual heading/dish
 * selection driven by the restaurant's configured language, "es" here — see
 * "Carpaccios" not "CARPACCIOS Carpaccios"), rule 1d (the "Carpaccios"
 * subsection is correctly flattened out of its "Entrants"/"Entrantes" parent
 * section, which never appears as a category of its own), and assembling
 * several real MenuImportPages belonging to ONE batch in a single
 * assemble() call — not just one.
 *
 * tests/fixtures/cervello.vision.json is the exact, human-verified vision
 * model output for all 4 pages (confirmed byte-for-byte per page against the
 * stored MenuImportPage rows this was frozen from), kept as the real
 * per-page structure the import actually produced — {"pages": [{"position",
 * "image", "detected_language", "categories"}, ...]} — rather than merged
 * into one flat list, so this test replays the real multi-page assemble()
 * flow. tests/fixtures/cervello.expected.json is the frozen correct
 * assembler result across all 4 pages combined. Both are static fixtures —
 * this test has no dependency on any restaurant/import data in a real
 * database; it creates its own throwaway restaurant and rolls back.
 *
 * Unlike Elian, no dish on this menu derives any allergen (the two dishes
 * with ingredients — "Sorbetes" and "Magnum" — list only fruits/private
 * flavor names, none of which carry a known allergen), so no Global
 * Ingredient Library or allergen seed data is needed here either.
 */
final class CervelloMenuFixtureTest extends KernelTestCase
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

    public function testCervelloMenuMatchesFrozenExpectation(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Test Cervello Restaurant');
        $restaurant->setSlug('test-cervello-restaurant');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $visionData = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/cervello.vision.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);

        // One real MenuImportPage per page in the fixture — replaying the
        // actual multi-page import, not a single merged page.
        foreach ($visionData['pages'] as $pageData) {
            $page = new MenuImportPage(
                $batch,
                $pageData['image'],
                $pageData['position'],
                hash('sha256', $pageData['image'])
            );
            $page->setStatus(MenuImportPageStatus::EXTRACTED);
            $page->setDetectedLocale($pageData['detected_language'] ?? 'es');
            $page->setExtractedData(['detected_language' => $pageData['detected_language'], 'categories' => $pageData['categories']]);
            $this->em->persist($page);
            $batch->addPage($page);
        }

        $this->em->flush();

        /** @var MenuImportAssembler $assembler */
        $assembler = self::getContainer()->get(MenuImportAssembler::class);
        $assembler->assemble($batch);

        $expected = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/cervello.expected.json'),
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
        self::assertCount(7, $categoryOrder, 'expected exactly 7 categories across all 4 pages');
        self::assertCount(7, $expectedCategories);

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
