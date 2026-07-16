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
 * Regression coverage for MenuVisionPromptBuilder rules 2 and 3 (dish name /
 * ingredient extraction), exercised through the assembler with the JSON
 * shape those rules are meant to produce — not a prompt-string assertion,
 * since an LLM's behavior can't be unit tested directly.
 *
 * - "Ñoquis caseros (calabaza) con salsa de queso parmesano": an ingredient
 *   enumeration mid-entry (more text follows it) — "name" stays whole,
 *   verbatim, never split into name + description; only "calabaza" is
 *   extracted ("queso parmesano" is descriptive wording, not an enumeration).
 * - "Ensalada verde con remolacha (mézclum con tomate, apio, hinojo,
 *   pepino)": the enumeration is the LAST thing printed, so it IS stripped
 *   from "name" — but within it, "mézclum con tomate" is transcribed
 *   verbatim as one item, never split into "mézclum" and "tomate" just
 *   because it contains "con" — a connector binds words into one item.
 * - "Ensalada del chef (lechuga, tomate, virutas de queso y vinagreta)": the
 *   final "y" IS a list separator (unlike "con" above) — it splits into
 *   "virutas de queso" and "vinagreta" as two distinct items, while "de"
 *   stays inside "virutas de queso"'s own name. Same grammatical reasoning,
 *   opposite outcome depending on which word is doing the connecting.
 */
final class MenuImportAssemblerTest extends KernelTestCase
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

    public function testNoquisCaserosConSalsaDeQuesoParmesano(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Test Restaurant');
        $restaurant->setSlug('test-noquis-restaurant');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);

        $page = new MenuImportPage($batch, 'test.jpg', 0, hash('sha256', 'test'));
        $page->setStatus(MenuImportPageStatus::EXTRACTED);
        $page->setDetectedLocale('es');
        $page->setExtractedData([
            'detected_language' => 'es',
            'categories' => [
                [
                    'name' => 'Primeros',
                    'products' => [
                        [
                            'name' => 'Ñoquis caseros (calabaza) con salsa de queso parmesano',
                            'name_uncertain' => false,
                            'description' => null,
                            'description_uncertain' => false,
                            'price' => 12.50,
                            'price_uncertain' => false,
                            'supplement_price' => null,
                            'recommended' => false,
                            'ingredients' => [
                                ['name' => 'calabaza', 'uncertain' => false],
                            ],
                            'dietary_markers' => [],
                        ],
                    ],
                ],
            ],
        ]);
        $this->em->persist($page);
        $batch->addPage($page);

        $this->em->flush();

        /** @var MenuImportAssembler $assembler */
        $assembler = self::getContainer()->get(MenuImportAssembler::class);
        $assembler->assemble($batch);

        $product = $this->em->getRepository(Product::class)->findOneBy(['importBatch' => $batch]);
        self::assertNotNull($product, 'assemble() should have created exactly one product for this dish');

        // 1. Name is the FULL printed line, verbatim — parenthesis included,
        // nothing split off into an invented description.
        $translation = $product->getTranslation('es');
        self::assertNotNull($translation);
        self::assertSame('Ñoquis caseros (calabaza) con salsa de queso parmesano', $translation->getName());
        self::assertNull($translation->getDescription());

        // 2. Ingredients: ONLY calabaza, from the parenthesized list.
        // "queso parmesano" must NOT be extracted — it's descriptive wording
        // in the name, not an explicit ingredient list.
        $privateLinks = $product->getIngredientLinks();
        self::assertCount(1, $privateLinks);
        $calabazaLink = $privateLinks->first();
        self::assertSame('calabaza', $calabazaLink->getIngredient()->getTranslation('es')?->getName());
        self::assertFalse($calabazaLink->isAiSuggested());

        self::assertCount(0, $product->getGlobalIngredientLinks(), 'queso parmesano must not be extracted from descriptive name wording');

        // 3. No false-positive allergen from calabaza (plain pumpkin).
        $resolver = self::getContainer()->get(ProductAllergenResolver::class);
        self::assertCount(0, $resolver->resolveForProduct($product));
    }

    /**
     * Regression coverage for "Ensalada verde con remolacha (mézclum con
     * tomate, apio, hinojo, pepino)": the trailing enumeration has nothing
     * printed after it, so it's stripped from "name" (unlike the Ñoquis
     * case above) — but within that enumeration, "mézclum con tomate" is a
     * single comma-separated item and must never be split into "mézclum"
     * and "tomate" just because it contains "con". See
     * MenuVisionPromptBuilder rules 2 and 3.
     */
    public function testEnsaladaVerdeConRemolachaDoesNotSplitOnCon(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Test Restaurant');
        $restaurant->setSlug('test-ensalada-restaurant');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);

        $page = new MenuImportPage($batch, 'test.jpg', 0, hash('sha256', 'test'));
        $page->setStatus(MenuImportPageStatus::EXTRACTED);
        $page->setDetectedLocale('es');
        $page->setExtractedData([
            'detected_language' => 'es',
            'categories' => [
                [
                    'name' => 'Ensaladas',
                    'products' => [
                        [
                            'name' => 'Ensalada verde con remolacha',
                            'name_uncertain' => false,
                            'description' => null,
                            'description_uncertain' => false,
                            'price' => 9.50,
                            'price_uncertain' => false,
                            'supplement_price' => null,
                            'recommended' => false,
                            'ingredients' => [
                                ['name' => 'mézclum con tomate', 'uncertain' => false],
                                ['name' => 'apio', 'uncertain' => false],
                                ['name' => 'hinojo', 'uncertain' => false],
                                ['name' => 'pepino', 'uncertain' => false],
                            ],
                            'dietary_markers' => [],
                        ],
                    ],
                ],
            ],
        ]);
        $this->em->persist($page);
        $batch->addPage($page);

        $this->em->flush();

        /** @var MenuImportAssembler $assembler */
        $assembler = self::getContainer()->get(MenuImportAssembler::class);
        $assembler->assemble($batch);

        $product = $this->em->getRepository(Product::class)->findOneBy(['importBatch' => $batch]);
        self::assertNotNull($product, 'assemble() should have created exactly one product for this dish');

        $translation = $product->getTranslation('es');
        self::assertNotNull($translation);
        self::assertSame('Ensalada verde con remolacha', $translation->getName());
        self::assertNull($translation->getDescription());

        // Exactly 4 ingredients, verbatim — "mézclum con tomate" stays whole.
        $links = $product->getIngredientLinks();
        self::assertCount(4, $links);
        $names = array_map(
            static fn ($link) => $link->getIngredient()->getTranslation('es')?->getName(),
            $links->toArray()
        );
        self::assertSame(['mézclum con tomate', 'apio', 'hinojo', 'pepino'], $names);
    }

    /**
     * Regression coverage for "Ensalada del chef (lechuga, tomate, virutas
     * de queso y vinagreta)": here the final "y" IS a Spanish list
     * separator — opposite of "con" in the test above — so it splits into
     * "virutas de queso" and "vinagreta" as two distinct items, while "de"
     * stays inside "virutas de queso"'s own name. See MenuVisionPromptBuilder
     * rule 3's language-grammar principle.
     */
    public function testEnsaladaDelChefSplitsOnFinalY(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Test Restaurant');
        $restaurant->setSlug('test-ensalada-chef-restaurant');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);

        $page = new MenuImportPage($batch, 'test.jpg', 0, hash('sha256', 'test'));
        $page->setStatus(MenuImportPageStatus::EXTRACTED);
        $page->setDetectedLocale('es');
        $page->setExtractedData([
            'detected_language' => 'es',
            'categories' => [
                [
                    'name' => 'Ensaladas',
                    'products' => [
                        [
                            'name' => 'Ensalada del chef',
                            'name_uncertain' => false,
                            'description' => null,
                            'description_uncertain' => false,
                            'price' => 10.50,
                            'price_uncertain' => false,
                            'supplement_price' => null,
                            'recommended' => false,
                            'ingredients' => [
                                ['name' => 'lechuga', 'uncertain' => false],
                                ['name' => 'tomate', 'uncertain' => false],
                                ['name' => 'virutas de queso', 'uncertain' => false],
                                ['name' => 'vinagreta', 'uncertain' => false],
                            ],
                            'dietary_markers' => [],
                        ],
                    ],
                ],
            ],
        ]);
        $this->em->persist($page);
        $batch->addPage($page);

        $this->em->flush();

        /** @var MenuImportAssembler $assembler */
        $assembler = self::getContainer()->get(MenuImportAssembler::class);
        $assembler->assemble($batch);

        $product = $this->em->getRepository(Product::class)->findOneBy(['importBatch' => $batch]);
        self::assertNotNull($product, 'assemble() should have created exactly one product for this dish');

        $translation = $product->getTranslation('es');
        self::assertNotNull($translation);
        self::assertSame('Ensalada del chef', $translation->getName());
        self::assertNull($translation->getDescription());

        // Exactly 4 ingredients — "virutas de queso" stays whole (the "de"
        // is part of its own name), while the final "y" correctly splits
        // "virutas de queso" from "vinagreta" into two separate items.
        $links = $product->getIngredientLinks();
        self::assertCount(4, $links);
        $names = array_map(
            static fn ($link) => $link->getIngredient()->getTranslation('es')?->getName(),
            $links->toArray()
        );
        self::assertSame(['lechuga', 'tomate', 'virutas de queso', 'vinagreta'], $names);
    }
}
