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
 * The two checks MenuImportAssembler makes against a PDF page's own text
 * (MenuImportPage::$sourceText): a section that continues on the next page
 * (the AI, reading that page alone, invents a heading for it), and
 * "Copa 3,80 € · Botella 18,00 €" (the AI returns one dish per price).
 * Shaped after the real "Marea Alta" import. Fake extraction JSON only:
 * no AI call.
 */
final class MenuImportAssemblerPdfTextTest extends KernelTestCase
{
    private const PAGE_1 = <<<TXT
        De la brasa
        Asado sobre brasa de encina.

        Entrecot de vaca madurada (350 g)                                             26,00 €
        Con patatas fritas caseras y pimientos asados.
        TXT;

    private const PAGE_2 = <<<TXT
        Medio pollo de corral                                                         16,50 €
        Marinado con limón, ajo y romero.

        BEBIDAS
        Vino blanco de la casa                                 Copa 3,80 € · Botella 18,00 €
        Verdejo, D.O. Rueda. (Alérgenos: sulfitos)

        Cava brut                                              Copa 4,50 € · Botella 24,00 €
        D.O. Cava.
        TXT;

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
        $restaurant->setName('PDF Text Test');
        $restaurant->setSlug('test-pdf-text-' . uniqid());
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        return $restaurant;
    }

    private static function dish(string $name, ?float $price, ?string $description = null): array
    {
        return [
            'name' => $name, 'name_uncertain' => false, 'description' => $description, 'description_uncertain' => false,
            'price' => $price, 'price_uncertain' => false, 'supplement_price' => null, 'recommended' => false,
            'ingredients' => [], 'dietary_markers' => [],
        ];
    }

    /**
     * @param list<array{categories: list<array>, text: ?string, pdfPage?: ?int, status?: MenuImportPageStatus}> $pages
     */
    private function batch(Restaurant $restaurant, array $pages): MenuImportBatch
    {
        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);
        foreach ($pages as $position => $pageData) {
            $page = new MenuImportPage($batch, "test-{$position}.jpg", $position, hash('sha256', uniqid('', true)));
            $page->setStatus($pageData['status'] ?? MenuImportPageStatus::EXTRACTED);
            $page->setDetectedLocale('es');
            $page->setExtractedData(['detected_language' => 'es', 'categories' => $pageData['categories']]);
            $page->setSourceText($pageData['text']);
            $page->setPdfPageNumber($pageData['pdfPage'] ?? null);
            $this->em->persist($page);
            $batch->addPage($page);
        }
        $this->em->flush();

        return $batch;
    }

    /** @return array<string, list<string>> category name => its dish names, in order */
    private function carta(Restaurant $restaurant): array
    {
        $carta = [];
        foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant], ['position' => 'ASC', 'id' => 'ASC']) as $category) {
            $carta[$category->getTranslation('es')?->getName()] = array_map(
                static fn (Product $p) => $p->getTranslation('es')?->getName(),
                $category->getProducts()->toArray(),
            );
        }

        return $carta;
    }

    /** @return list<array<string, mixed>> the AI's two pages of the real case, with "Platos Principales" invented on page 2 */
    private static function mareaAltaPages(?string $text1, ?string $text2): array
    {
        return [
            ['text' => $text1, 'pdfPage' => 1, 'categories' => [
                ['name' => 'De la brasa', 'products' => [self::dish('Entrecot de vaca madurada (350 g)', 26.0)]],
            ]],
            ['text' => $text2, 'pdfPage' => 2, 'categories' => [
                ['name' => 'Platos Principales', 'products' => [self::dish('Medio pollo de corral', 16.5)]],
                ['name' => 'Bebidas', 'products' => [
                    self::dish('Vino blanco de la casa', 3.8, 'Verdejo, D.O. Rueda.'),
                    self::dish('Vino blanco de la casa', 18.0, 'Verdejo, D.O. Rueda.'),
                    self::dish('Cava brut', 4.5, 'D.O. Cava.'),
                    self::dish('Cava brut', 24.0, 'D.O. Cava.'),
                ]],
            ]],
        ];
    }

    public function testAnInventedHeadingAtTheTopOfAPageJoinsThePreviousPagesLastSection(): void
    {
        $restaurant = $this->restaurant();
        $batch = $this->batch($restaurant, self::mareaAltaPages(self::PAGE_1, self::PAGE_2));

        $result = $this->assembler->assemble($batch);

        self::assertSame([
            'De la brasa' => ['Entrecot de vaca madurada (350 g)', 'Medio pollo de corral'],
            // "BEBIDAS" is printed (in capitals): a real heading, kept.
            'Bebidas' => ['Vino blanco de la casa', 'Cava brut'],
        ], $this->carta($restaurant));
        self::assertSame(2, $result->categoriesCreated, 'no "Platos Principales"');

        $pollo = $this->em->getRepository(Product::class)->findOneBy(['importBatch' => $batch, 'basePrice' => 1650]);
        self::assertSame(1, $pollo->getPosition(), 'after the previous page\'s dishes');
        self::assertTrue($pollo->isActive());
        self::assertFalse($pollo->isImportDuplicate());
    }

    public function testWithoutPageTextNothingChanges(): void
    {
        // A photo, or a scanned PDF: exactly the behaviour before this check.
        $restaurant = $this->restaurant();
        $batch = $this->batch($restaurant, self::mareaAltaPages(null, null));

        $this->assembler->assemble($batch);

        self::assertSame([
            'De la brasa' => ['Entrecot de vaca madurada (350 g)'],
            'Platos Principales' => ['Medio pollo de corral'],
            'Bebidas' => ['Vino blanco de la casa', 'Vino blanco de la casa', 'Cava brut', 'Cava brut'],
        ], $this->carta($restaurant));
        $repeated = $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch, 'importDuplicate' => true]);
        self::assertCount(2, $repeated, 'the 2nd price of each wine is still "Repetido"');
    }

    public function testAHeadingPrintedOnThePageIsKept(): void
    {
        $restaurant = $this->restaurant();
        $batch = $this->batch($restaurant, [
            ['text' => self::PAGE_1, 'pdfPage' => 1, 'categories' => [
                ['name' => 'De la brasa', 'products' => [self::dish('Entrecot de vaca madurada (350 g)', 26.0)]],
            ]],
            ['text' => "Arroces\nArroz negro    18,00 €", 'pdfPage' => 2, 'categories' => [
                ['name' => 'Arroces', 'products' => [self::dish('Arroz negro', 18.0)]],
            ]],
        ]);

        $this->assembler->assemble($batch);

        self::assertSame(['De la brasa' => ['Entrecot de vaca madurada (350 g)'], 'Arroces' => ['Arroz negro']], $this->carta($restaurant));
    }

    public function testOnlyTheFirstHeadingOfAPageAndNeverOnPageOne(): void
    {
        $restaurant = $this->restaurant();
        $batch = $this->batch($restaurant, [
            // Page 1 has no previous page: its headings are never moved.
            ['text' => 'Croquetas    9,50 €', 'pdfPage' => 1, 'categories' => [
                ['name' => 'Para picar', 'products' => [self::dish('Croquetas', 9.5)]],
            ]],
            // Page 2 opens with a printed heading; a LATER heading not in the
            // text is a normal category, not a continuation.
            ['text' => "Postres\nFlan    5,00 €\nTarta    6,00 €", 'pdfPage' => 2, 'categories' => [
                ['name' => 'Postres', 'products' => [self::dish('Flan', 5.0)]],
                ['name' => 'Dulces', 'products' => [self::dish('Tarta', 6.0)]],
            ]],
        ]);

        $this->assembler->assemble($batch);

        self::assertSame([
            'Para picar' => ['Croquetas'],
            'Postres' => ['Flan'],
            'Dulces' => ['Tarta'],
        ], $this->carta($restaurant));
    }

    public function testNeverCarriesASectionAcrossAFailedPage(): void
    {
        $restaurant = $this->restaurant();
        $pages = self::mareaAltaPages(self::PAGE_1, self::PAGE_2);
        $pages[1]['pdfPage'] = 3;
        array_splice($pages, 1, 0, [['text' => null, 'pdfPage' => 2, 'categories' => [], 'status' => MenuImportPageStatus::FAILED]]);
        $batch = $this->batch($restaurant, $pages);

        $this->assembler->assemble($batch);

        self::assertArrayHasKey('Platos Principales', $this->carta($restaurant), 'the page in between is missing: no guess');
    }

    public function testAPdfsFirstPageNeverContinuesWhatWasSentBeforeIt(): void
    {
        // One upload: a 2-page PDF, a 2nd PDF, then a photo. Only page 2 of
        // the 1st PDF continues a section; the 2nd PDF's first page opens
        // with a heading not in its text, and is still its own section.
        $restaurant = $this->restaurant();
        $batch = $this->batch($restaurant, [
            ['text' => self::PAGE_1, 'pdfPage' => 1, 'categories' => [
                ['name' => 'De la brasa', 'products' => [self::dish('Entrecot de vaca madurada (350 g)', 26.0)]],
            ]],
            ['text' => "Medio pollo de corral    16,50 €", 'pdfPage' => 2, 'categories' => [
                ['name' => 'Platos Principales', 'products' => [self::dish('Medio pollo de corral', 16.5)]],
            ]],
            ['text' => "Tarta de queso    6,50 €", 'pdfPage' => 1, 'categories' => [
                ['name' => 'Postres', 'products' => [self::dish('Tarta de queso', 6.5)]],
            ]],
            ['text' => null, 'pdfPage' => null, 'categories' => [
                ['name' => 'Cafés', 'products' => [self::dish('Cortado', 1.8)]],
            ]],
        ]);

        $this->assembler->assemble($batch);

        self::assertSame([
            'De la brasa' => ['Entrecot de vaca madurada (350 g)', 'Medio pollo de corral'],
            'Postres' => ['Tarta de queso'],
            'Cafés' => ['Cortado'],
        ], $this->carta($restaurant));
    }

    public function testAPhotoNeverContinuesAndNothingContinuesAPhoto(): void
    {
        // Even with text on both sides of the check, a page without a PDF
        // page number (a photo) is never part of a continuation.
        $restaurant = $this->restaurant();
        $pages = self::mareaAltaPages(self::PAGE_1, self::PAGE_2);
        $pages[0]['pdfPage'] = null;
        $pages[1]['pdfPage'] = 1;
        $batch = $this->batch($restaurant, $pages);

        $this->assembler->assemble($batch);

        self::assertArrayHasKey('Platos Principales', $this->carta($restaurant));
    }

    public function testGlassAndBottleBecomeOneDishWithLabelledPrices(): void
    {
        $restaurant = $this->restaurant();
        $batch = $this->batch($restaurant, self::mareaAltaPages(self::PAGE_1, self::PAGE_2));

        $result = $this->assembler->assemble($batch);

        self::assertSame(4, $result->productsCreated, 'entrecot, pollo, 1 wine, 1 cava');

        foreach (['Vino blanco de la casa' => [380, 1800], 'Cava brut' => [450, 2400]] as $name => [$glass, $bottle]) {
            $matches = array_values(array_filter(
                $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch]),
                static fn (Product $p) => $p->getTranslation('es')?->getName() === $name,
            ));
            self::assertCount(1, $matches, "{$name}: one dish, not one per price");
            $product = $matches[0];
            self::assertSame($glass, $product->getBasePrice());
            self::assertSame('Copa', $product->getBasePriceLabel());
            self::assertCount(1, $product->getPriceVariants());
            $variant = $product->getPriceVariants()->first();
            self::assertSame('Botella', $variant->getLabel());
            self::assertSame($bottle, $variant->getPrice());
            self::assertSame(0, $variant->getPosition());
            self::assertTrue($product->isActive());
            self::assertFalse($product->isImportDuplicate());
        }
    }

    public function testPricesThatDontMatchTheTextStaySeparate(): void
    {
        // The AI misread a price: the line's prices don't match, so no labels — never invented.
        $restaurant = $this->restaurant();
        $batch = $this->batch($restaurant, [
            ['text' => self::PAGE_2, 'categories' => [
                ['name' => 'Bebidas', 'products' => [
                    self::dish('Cava brut', 4.5, 'D.O. Cava.'),
                    self::dish('Cava brut', 25.0, 'D.O. Cava.'),
                ]],
            ]],
        ]);

        $this->assembler->assemble($batch);

        self::assertSame(['Bebidas' => ['Cava brut', 'Cava brut']], $this->carta($restaurant));
        $repeated = $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch, 'importDuplicate' => true]);
        self::assertCount(1, $repeated);
        self::assertNull($repeated[0]->getBasePriceLabel());
    }

    public function testOnlyConsecutiveEntriesWithTheSameDescriptionAreJoined(): void
    {
        $restaurant = $this->restaurant();
        $batch = $this->batch($restaurant, [
            ['text' => self::PAGE_2, 'categories' => [
                ['name' => 'Bebidas', 'products' => [
                    // Not consecutive.
                    self::dish('Vino blanco de la casa', 3.8, 'Verdejo, D.O. Rueda.'),
                    self::dish('Cava brut', 4.5, 'D.O. Cava.'),
                    self::dish('Vino blanco de la casa', 18.0, 'Verdejo, D.O. Rueda.'),
                    // Different description.
                    self::dish('Cava brut', 24.0, 'Otro cava.'),
                ]],
            ]],
        ]);

        $this->assembler->assemble($batch);

        self::assertCount(4, $this->carta($restaurant)['Bebidas']);
    }

    public function testLabelsFromText(): void
    {
        $vino = [self::dish('Vino blanco de la casa', 3.8), self::dish('Vino blanco de la casa', 18.0)];

        // No gap between the name and a price: "Vino blanco" would be a label — refused.
        self::assertNull(MenuImportAssembler::groupPriceVariants($vino, 'Vino blanco de la casa 3,80 € · Botella 18,00 €')[0][1]);
        // Labels with a colon, a leading currency sign, several words.
        self::assertSame(
            [['label' => 'Media copa', 'cents' => 380], ['label' => 'Botella', 'cents' => 1800]],
            MenuImportAssembler::groupPriceVariants($vino, 'Vino blanco de la casa      Media copa: €3.80   Botella €18')[0][1],
        );
        // The same label twice: refused.
        self::assertNull(MenuImportAssembler::groupPriceVariants($vino, 'Vino blanco de la casa      Copa 3,80 € · Copa 18,00 €')[0][1]);
    }

    public function testIsMissingFromText(): void
    {
        self::assertFalse(MenuImportAssembler::isMissingFromText('Platos Principales', null), 'no text: never missing');
        self::assertTrue(MenuImportAssembler::isMissingFromText('Platos Principales', self::PAGE_2));
        self::assertFalse(MenuImportAssembler::isMissingFromText('Bebidas', self::PAGE_2), 'case does not matter');
        self::assertFalse(MenuImportAssembler::isMissingFromText('Pescados del día', "PESCADOS\n   DEL DIA"), 'line breaks and accents do not matter');
    }
}
