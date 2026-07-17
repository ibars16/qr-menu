<?php

namespace App\Tests\Service;

use App\Entity\Allergen;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientAllergen;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Enum\MenuImportPageStatus;
use App\Service\MenuImportAssembler;
use App\Service\ProductAllergenResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Full-menu regression test, frozen from a real "Elian" restaurant menu
 * (tests/fixtures/elian_menu.png) that exercises every dish-name/ingredient
 * rule in MenuVisionPromptBuilder at once: bilingual-free plain Spanish,
 * mid-entry vs. trailing ingredient enumerations, "con" binding vs. "y"
 * separating, supplement spelling variants, a menu-wide non-dish condition
 * excluded from the dessert list, and the allergen cascade.
 *
 * tests/fixtures/elian_menu.vision.json is the exact, human-verified vision
 * model output for that image (confirmed byte-for-byte against the stored
 * MenuImportPage this was frozen from). tests/fixtures/elian_menu.expected.json
 * is the frozen correct assembler result for it. Both are static fixtures —
 * this test has no dependency on any restaurant/import data in a real
 * database; it creates its own throwaway restaurant and rolls back.
 *
 * The one non-empty allergen in this menu (celery, from "apio") depends on
 * the real config/global_ingredient_allergens.yaml mapping — reproduced here
 * as a minimal, self-contained fixture rather than depending on the real
 * ~4,700-row Global Ingredient Library being seeded (it isn't, in the test
 * database). Every other ingredient on this menu has no known allergen and
 * is asserted as such — including "nueces" (walnut/tree-nuts), which does
 * NOT currently match the Global Library by exact name ("nueces" vs. the
 * library's "Nuez") and is therefore expected to carry no allergen. That is
 * current, deliberate, verified behavior — not a gap this test should paper
 * over. If the Global Library's translation or matching logic ever changes
 * to fix that particular mismatch, this fixture must be regenerated, not
 * patched in place.
 */
final class ElianMenuFixtureTest extends KernelTestCase
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

    public function testElianMenuMatchesFrozenExpectation(): void
    {
        // The only Global Ingredient Library fact this fixture depends on:
        // "apio" carries the celery allergen (config/global_ingredient_allergens.yaml).
        // The allergen taxonomy itself may already be seeded (app:allergens:seed) —
        // reuse it rather than assume a bare database.
        $celery = $this->em->getRepository(Allergen::class)->findOneBy(['code' => 'celery']);
        if ($celery === null) {
            $celery = new Allergen();
            $celery->setCode('celery');
            $this->em->persist($celery);
        }

        $apio = new GlobalIngredient();
        $apio->setCode('celery-stick');
        $this->em->persist($apio);

        $apioTranslation = new GlobalIngredientTranslation();
        $apioTranslation->setLocale('es');
        $apioTranslation->setName('apio');
        $apio->addTranslation($apioTranslation);
        $this->em->persist($apioTranslation);

        $apioAllergen = new GlobalIngredientAllergen();
        $apioAllergen->setAllergen($celery);
        $apioAllergen->setPresence(AllergenPresence::CONTAINS);
        $apio->addAllergenLink($apioAllergen);
        $this->em->persist($apioAllergen);

        $restaurant = new Restaurant();
        $restaurant->setName('Test Elian Restaurant');
        $restaurant->setSlug('test-elian-restaurant');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        // Normally seeded by DefaultTagSeeder at restaurant creation — this
        // menu marks exactly one dish "vegan" (see MenuVisionPromptBuilder's
        // KNOWN_DIETARY_CODES), and MenuImportAssembler only ever assigns a
        // dietary marker if the matching preset ProductTag already exists.
        $veganTag = new ProductTag($restaurant, 'vegan', true);
        $this->em->persist($veganTag);

        $visionData = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/elian_menu.vision.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);

        $page = new MenuImportPage($batch, 'elian_menu.png', 0, hash('sha256', 'elian_menu'));
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
            file_get_contents(__DIR__ . '/../fixtures/elian_menu.expected.json'),
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
        self::assertCount(3, $categoryOrder, 'expected exactly 3 categories: Entrantes, Segundos, Postres');
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
