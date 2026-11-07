<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\MenuSection;
use App\Entity\OwnerTextTranslation;
use App\Entity\Product;
use App\Entity\ProductGlobalIngredient;
use App\Entity\ProductIngredient;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Repository\OwnerTextTranslationRepository;
use App\Service\OwnerTextTranslationPruner;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * OwnerTextTranslationPruner: deletes the owner_text_translation rows
 * nothing in the restaurant refers to any more, and only those — a text
 * used in two places stays while one is left, an inactive dish's labels
 * stay, an owner's original ('human') stays while its text is there,
 * ingredient names stay while a dish uses the ingredient. Nothing while a
 * main-language change is half done; another restaurant's rows never.
 */
final class OwnerTextTranslationPrunerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private OwnerTextTranslationRepository $repository;
    /** @var int[] */
    private array $restaurantIds = [];
    private ?int $globalIngredientId = null;

    private Restaurant $restaurant;
    private Product $wine;
    private Product $beer;
    private Ingredient $saffron;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = static::getContainer()->get(Connection::class);
        $this->repository = static::getContainer()->get(OwnerTextTranslationRepository::class);
        $this->restaurant = $this->makeRestaurant();
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIds as $id) {
            // Join rows and variants go with their dish (ON DELETE CASCADE).
            $this->conn->executeStatement('DELETE FROM product_translation WHERE product_id IN (SELECT p.id FROM product p JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM product WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM menu_section WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category_translation WHERE category_id IN (SELECT id FROM category WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM category WHERE restaurant_id = ?', [$id]);
            $this->conn->executeStatement('DELETE FROM ingredient_translation WHERE ingredient_id IN (SELECT id FROM ingredient WHERE restaurant_id = ?)', [$id]);
            $this->conn->executeStatement('DELETE FROM ingredient WHERE restaurant_id = ?', [$id]);
            // owner_text_translation goes with the restaurant (ON DELETE CASCADE).
            $this->conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$id]);
        }
        if ($this->globalIngredientId !== null) {
            $this->conn->executeStatement('DELETE FROM global_ingredient_translation WHERE global_ingredient_id = ?', [$this->globalIngredientId]);
            $this->conn->executeStatement('DELETE FROM global_ingredient WHERE id = ?', [$this->globalIngredientId]);
        }

        parent::tearDown();
    }

    // ── Fixture ──

    /**
     * Spanish: tagline; a wine (Botella / Copa) and an inactive beer (Copa
     * too, and Tercio) with saffron (private) and egg (global); a set menu
     * with a description and a section.
     */
    private function makeRestaurant(string $slug = 'pruner-test-'): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Pruner Test');
        $restaurant->setSlug($slug . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTagline('Cocina de mercado.');
        $this->em->persist($restaurant);

        $drinks = new Category();
        $drinks->setActive(true);
        $restaurant->addCategory($drinks);
        $this->em->persist($drinks);
        $this->label($drinks, CategoryTranslation::class, 'Bebidas');

        $this->wine = $this->dish($drinks, 'Vino tinto', 'Botella', ['Copa']);
        $this->beer = $this->dish($drinks, 'Cerveza', null, ['Copa', 'Tercio']);
        $this->beer->setActive(false);

        $menu = new Category();
        $menu->setActive(true);
        $menu->setMenuPrice(1500);
        $menu->setMenuDescription('Pan, bebida y postre');
        $restaurant->addCategory($menu);
        $this->em->persist($menu);
        $this->label($menu, CategoryTranslation::class, 'Menú del día');
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $menu->addMenuSection($section);
        $this->em->persist($section);

        $this->saffron = new Ingredient();
        $this->saffron->setCode('pruner-test-' . uniqid());
        $this->saffron->setRestaurant($restaurant);
        $this->em->persist($this->saffron);
        $t = new IngredientTranslation();
        $t->setLocale('es');
        $t->setName('Azafrán');
        $this->saffron->addTranslation($t);
        $this->em->persist($t);
        $link = new ProductIngredient();
        $link->setIngredient($this->saffron);
        $link->setPosition(0);
        $this->beer->addIngredientLink($link);
        $this->em->persist($link);

        if ($this->globalIngredientId === null) {
            $egg = new GlobalIngredient();
            $egg->setCode('pruner-test-egg-' . uniqid());
            $this->em->persist($egg);
            $t = new GlobalIngredientTranslation();
            $t->setLocale('en');
            $t->setName('Egg');
            $t->setSource('dataset');
            $egg->addTranslation($t);
            $this->em->persist($t);
            $this->em->flush();
            $this->globalIngredientId = $egg->getId();
        }
        $link = new ProductGlobalIngredient();
        $link->setGlobalIngredient($this->em->find(GlobalIngredient::class, $this->globalIngredientId));
        $link->setPosition(1);
        $this->beer->addGlobalIngredientLink($link);
        $this->em->persist($link);

        $this->em->flush();
        $this->restaurantIds[] = $restaurant->getId();

        return $restaurant;
    }

    private function label(object $owner, string $class, string $name): void
    {
        $t = new $class();
        $t->setLocale('es');
        $t->setName($name);
        $owner->addTranslation($t);
        $this->em->persist($t);
    }

    /** @param string[] $variants */
    private function dish(Category $category, string $name, ?string $baseLabel, array $variants): Product
    {
        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice(1000);
        $product->setBasePriceLabel($baseLabel);
        foreach ($variants as $i => $label) {
            $variant = new ProductPriceVariant();
            $variant->setLabel($label);
            $variant->setPrice(400);
            $variant->setPosition($i);
            $product->addPriceVariant($variant);
        }
        $category->addProduct($product);
        $this->em->persist($product);
        $this->label($product, ProductTranslation::class, $name);

        return $product;
    }

    private function row(string $locale, string $sourceLocale, string $text, string $origin = OwnerTextTranslation::ORIGIN_AI, ?Restaurant $restaurant = null): void
    {
        $this->repository->insertIfMissing($restaurant ?? $this->restaurant, $locale, $sourceLocale, $text, "[{$locale}] {$text}", $origin);
    }

    private function prune(?Restaurant $restaurant = null): int
    {
        $this->em->flush();

        return static::getContainer()->get(OwnerTextTranslationPruner::class)->prune($restaurant ?? $this->restaurant);
    }

    /** @return list<string> "locale|source text|origin", sorted */
    private function rows(?Restaurant $restaurant = null): array
    {
        $rows = array_map(
            static fn (array $r) => implode('|', $r),
            $this->conn->fetchAllNumeric(
                'SELECT locale, source_text, origin FROM owner_text_translation WHERE restaurant_id = ? ORDER BY source_text, locale',
                [($restaurant ?? $this->restaurant)->getId()],
            ),
        );

        return $rows;
    }

    // ── Tests ──

    public function testEveryTextStillThereIsKeptAndTheRestGoes(): void
    {
        foreach (['Cocina de mercado.', 'Botella', 'Copa', 'Tercio', 'Pan, bebida y postre', 'Primeros'] as $text) {
            $this->row('fr', 'es', $text);
        }
        $this->row('fr', 'es', 'Cocina de temporada.');   // an old tagline
        $this->row('fr', 'es', 'Media botella');          // an old label
        $this->row('fr', 'en', 'Copa');                   // same text, another source language

        self::assertSame(3, $this->prune());
        self::assertSame([
            'fr|Botella|ai', 'fr|Cocina de mercado.|ai', 'fr|Copa|ai', 'fr|Pan, bebida y postre|ai', 'fr|Primeros|ai', 'fr|Tercio|ai',
        ], $this->rows());
    }

    /** "Copa" is on the wine and the (inactive) beer: rewritten on one, it stays for the other. */
    public function testATextUsedInTwoPlacesStaysWhileOneIsLeft(): void
    {
        $this->row('fr', 'es', 'Copa');
        $this->wine->getPriceVariants()->first()->setLabel('Copa 15 cl');

        self::assertSame(0, $this->prune());
        self::assertSame(['fr|Copa|ai'], $this->rows());

        $this->beer->getPriceVariants()->first()->setLabel('Caña');
        self::assertSame(1, $this->prune());
        self::assertSame([], $this->rows());
    }

    /** The owner's original stays while its (current) text does; one whose text they rewrote goes. */
    public function testAnOwnersOriginalStaysWhileItsTextIsThereAndGoesOnceRewritten(): void
    {
        $restaurant = $this->restaurant;
        $restaurant->setDefaultLanguage('en');
        $restaurant->setTagline('Market cooking.');
        $this->row('es', 'en', 'Market cooking.', OwnerTextTranslation::ORIGIN_HUMAN);
        $this->row('es', 'en', 'Seasonal cooking.', OwnerTextTranslation::ORIGIN_HUMAN);

        self::assertSame(1, $this->prune());
        self::assertSame(['es|Market cooking.|human'], $this->rows());

        $restaurant->setTagline('Market cooking, every day.');
        self::assertSame(1, $this->prune());
        self::assertSame([], $this->rows());
    }

    /** Names of the ingredients a dish uses (any language, private or global) stay; once no dish uses one, its rows go. */
    public function testIngredientNamesStayWhileADishUsesThem(): void
    {
        $this->row('fr', 'es', 'Azafrán');
        $this->row('fr', 'en', 'Egg');
        $this->row('fr', 'es', 'Leche');   // no dish has it

        self::assertSame(1, $this->prune());
        self::assertSame(['fr|Azafrán|ai', 'fr|Egg|ai'], $this->rows());

        foreach ($this->beer->getIngredientLinks()->toArray() as $link) {
            $this->beer->removeIngredientLink($link);
        }
        foreach ($this->beer->getGlobalIngredientLinks()->toArray() as $link) {
            $this->beer->removeGlobalIngredientLink($link);
        }
        self::assertSame(2, $this->prune());
        self::assertSame([], $this->rows());
    }

    public function testNothingIsDeletedWhileAMainLanguageChangeIsHalfDone(): void
    {
        $this->row('fr', 'es', 'Cocina de temporada.');
        $this->row('es', 'en', 'Market cooking.', OwnerTextTranslation::ORIGIN_HUMAN);
        $this->restaurant->setLanguageChangeTo('en');

        self::assertSame(0, $this->prune());
        self::assertCount(2, $this->rows());

        $this->restaurant->setLanguageChangeTo(null);
        self::assertSame(2, $this->prune());
    }

    public function testAnotherRestaurantsRowsAreNeverTouched(): void
    {
        $other = $this->makeRestaurant('pruner-test-other-');
        $this->row('fr', 'es', 'Cocina de temporada.', restaurant: $other);
        $this->row('fr', 'es', 'Cocina de temporada.');

        self::assertSame(1, $this->prune());
        self::assertSame([], $this->rows());
        self::assertSame(['fr|Cocina de temporada.|ai'], $this->rows($other));
    }
}
