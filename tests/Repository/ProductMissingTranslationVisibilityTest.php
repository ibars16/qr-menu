<?php

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Repository\ProductRepository;
use App\Service\AiProductTranslator;
use App\Service\ProductTranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "missing translation" side follows Product::isShownOnMenu() too: a
 * dish the menu doesn't show (€0, blank default-language name) never sends
 * the menu through the loading screen (ProductRepository::
 * hasAnyMissingTranslation()) and is never sent to the AI translator
 * (AiProductTranslator). Before, both only looked at the active flags.
 *
 * The restaurant is registered for tearDown() before anything is flushed,
 * so a failure halfway through setUp() still cleans up.
 */
final class ProductMissingTranslationVisibilityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProductRepository $repo;
    private Restaurant $restaurant;
    private Category $category;

    /** @var Restaurant[] */
    private array $restaurantsToRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(ProductRepository::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Missing Translation Visibility Test');
        $this->restaurant->setSlug('missing-translation-visibility-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->em->persist($this->restaurant);
        $this->restaurantsToRemove[] = $this->restaurant;

        $this->category = new Category();
        $this->category->setRestaurant($this->restaurant);
        $t = new CategoryTranslation();
        $t->setLocale('es');
        $t->setName('Entrantes');
        $this->category->addTranslation($t);
        $this->em->persist($this->category);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantsToRemove as $restaurant) {
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /** @param array<string, string> $names locale → name */
    private function dish(array $names, int $price = 1000): Product
    {
        $product = new Product();
        $product->setCategory($this->category);
        $product->setBasePrice($price);
        foreach ($names as $locale => $name) {
            $t = new ProductTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $product->addTranslation($t);
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** @return array<int, array{name: string, description: ?string}>[] what each translate() call received */
    private function runTranslator(): array
    {
        $calls = [];
        $translator = $this->createStub(ProductTranslatorInterface::class);
        $translator->method('translate')->willReturnCallback(function (array $products) use (&$calls) {
            $calls[] = $products;

            return array_map(static fn (array $p) => ['name' => $p['name'] . ' EN', 'description' => null], $products);
        });

        (new AiProductTranslator($translator, $this->em, $this->repo))->translateMissing($this->restaurant, 'en');

        return $calls;
    }

    public function testAShownDishMissingTheLocaleCounts(): void
    {
        $dish = $this->dish(['es' => 'Croquetas']);

        self::assertTrue($this->repo->hasAnyMissingTranslation($this->restaurant, 'en'));
        self::assertSame([[$dish->getId() => ['name' => 'Croquetas', 'description' => null]]], $this->runTranslator());
    }

    public function testAZeroPricedDishNeitherCountsNorGetsTranslated(): void
    {
        $this->dish(['es' => 'Croquetas'], price: 0);

        self::assertFalse($this->repo->hasAnyMissingTranslation($this->restaurant, 'en'));
        self::assertSame([], $this->runTranslator());
    }

    public function testADishWithABlankDefaultNameNeitherCountsNorGetsTranslated(): void
    {
        // Named in French only — hidden by Product::hasMenuName(): the
        // default-language row exists and is blank.
        $this->dish(['es' => '', 'fr' => 'Croquettes']);

        self::assertFalse($this->repo->hasAnyMissingTranslation($this->restaurant, 'en'));
        self::assertSame([], $this->runTranslator());
    }

    public function testOnlyTheShownDishIsSentWhenBothAreMissing(): void
    {
        $shown = $this->dish(['es' => 'Croquetas']);
        $this->dish(['es' => 'Gratis'], price: 0);

        self::assertTrue($this->repo->hasAnyMissingTranslation($this->restaurant, 'en'));
        self::assertSame([[$shown->getId() => ['name' => 'Croquetas', 'description' => null]]], $this->runTranslator());
    }
}
