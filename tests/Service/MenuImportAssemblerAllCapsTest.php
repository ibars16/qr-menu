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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Names the AI read entirely in capitals are stored with only the first
 * letter capitalised; mixed case, short acronyms and scripts without case
 * are kept as read. Fake extraction JSON only: no AI call.
 */
final class MenuImportAssemblerAllCapsTest extends KernelTestCase
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

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'category' => ['ENTRANTES', 'Entrantes'];
        yield 'dish' => ['PULPO A LA GALLEGA', 'Pulpo a la gallega'];
        yield 'ñ and accents' => ['AÑEJO', 'Añejo'];
        yield 'accented words' => ['CORDERO ASADO CON PATATAS PANADERÍA', 'Cordero asado con patatas panadería'];
        yield 'leading digit' => ['1/2 RACIÓN DE CROQUETAS', '1/2 Ración de croquetas'];
        yield 'leading sign' => ['¡OFERTA DEL DÍA!', '¡Oferta del día!'];
        yield 'mixed case' => ['Pizza BBQ', 'Pizza BBQ'];
        yield 'mixed case proper names' => ['Canelones Casa Cervelló', 'Canelones Casa Cervelló'];
        yield 'already sentence case' => ['Pulpo a la gallega', 'Pulpo a la gallega'];
        yield 'short acronym' => ['BBQ', 'BBQ'];
        yield 'two-letter acronym' => ['XL', 'XL'];
        yield 'Chinese' => ['宫保鸡丁', '宫保鸡丁'];
        yield 'Japanese' => ['天ぷら盛り合わせ', '天ぷら盛り合わせ'];
        yield 'Arabic' => ['دجاج مشوي', 'دجاج مشوي'];
        yield 'Thai' => ['ผัดไทย', 'ผัดไทย'];
        yield 'German' => ['SCHWEINEBRATEN MIT KNÖDEL', 'Schweinebraten mit knödel'];
    }

    #[DataProvider('names')]
    public function testFromAllCaps(string $read, string $stored): void
    {
        self::assertSame($stored, MenuImportAssembler::fromAllCaps($read));
    }

    public function testImportStoresAllCapsNamesInSentenceCase(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('All Caps Test');
        $restaurant->setSlug('test-all-caps-' . uniqid());
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);
        $page = new MenuImportPage($batch, 'test.jpg', 0, hash('sha256', uniqid()));
        $page->setStatus(MenuImportPageStatus::EXTRACTED);
        $page->setDetectedLocale('es');
        $page->setExtractedData(['detected_language' => 'es', 'categories' => [
            ['name' => 'ENTRANTES', 'products' => [self::dish('PULPO A LA GALLEGA'), self::dish('Pizza BBQ'), self::dish('BBQ')]],
            ['name' => 'Postres CASEROS', 'products' => [self::dish('FLAN DE HUEVO AÑEJO'), self::dish('Canelones Casa Cervelló')]],
        ]]);
        $this->em->persist($page);
        $batch->addPage($page);
        $this->em->flush();

        $this->assembler->assemble($batch);

        $categories = $this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant], ['id' => 'ASC']);
        self::assertSame(['Entrantes', 'Postres CASEROS'], array_map(
            static fn (Category $c) => $c->getTranslation('es')?->getName(),
            $categories,
        ));

        $products = $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch], ['id' => 'ASC']);
        self::assertSame(
            ['Pulpo a la gallega', 'Pizza BBQ', 'BBQ', 'Flan de huevo añejo', 'Canelones Casa Cervelló'],
            array_map(static fn (Product $p) => $p->getTranslation('es')?->getName(), $products),
        );
    }

    private static function dish(string $name): array
    {
        return [
            'name' => $name, 'name_uncertain' => false, 'description' => null, 'description_uncertain' => false,
            'price' => 9.5, 'price_uncertain' => false, 'supplement_price' => null, 'recommended' => false,
            'ingredients' => [], 'dietary_markers' => [],
        ];
    }
}
