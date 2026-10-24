<?php

namespace App\Tests\Controller;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientAllergen;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\AllergenPresence;
use App\Enum\MenuImportPageStatus;
use App\Repository\CategoryRepository;
use App\Repository\IngredientRepository;
use App\Service\MenuImportAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A library ingredient brings its allergens along, so linking one nobody
 * picked (a typed "new:" name on product save, or an imported menu) must
 * be exact and unambiguous: a case-insensitive match in the menu's
 * language only, never when two library ingredients share that name.
 * Anything else becomes a private ingredient, as when nothing matches.
 *
 * Every name carries a uniqid() so the test database's own library rows
 * can never match.
 */
final class IngredientAutoLinkSafetyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $u;

    private ?int $restaurantId = null;
    private ?int $categoryId = null;
    private ?int $productId = null;
    /** @var int[] */
    private array $globalIngredientIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->u = uniqid();

        $restaurant = new Restaurant();
        $restaurant->setName('Casa Enlaces');
        $restaurant->setSlug('auto-link-test-' . $this->u);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setRestaurant($restaurant);
        $this->em->persist($category);

        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice(1000);
        $this->em->persist($product);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('auto-link-test-' . $this->u . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->categoryId = $category->getId();
        $this->productId = $product->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->restaurantId ? $this->em->getRepository(Restaurant::class)->find($this->restaurantId) : null;
        if ($restaurant) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush();
                $this->em->remove($category);
            }
            foreach ($this->em->getRepository(MenuImportBatch::class)->findBy(['restaurant' => $restaurant]) as $batch) {
                $this->em->remove($batch);
            }
            $this->em->flush();
            foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
                $this->em->remove($ingredient);
            }
            $this->em->remove($restaurant);
        }
        foreach ($this->globalIngredientIds as $id) {
            $globalIngredient = $this->em->getRepository(GlobalIngredient::class)->find($id);
            if ($globalIngredient) {
                $this->em->remove($globalIngredient);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    /**
     * @param array<string, string>               $names     locale => name
     * @param array<string, AllergenPresence>     $allergens allergen code => presence
     */
    private function globalIngredient(array $names, array $allergens = []): int
    {
        $ingredient = new GlobalIngredient();
        $ingredient->setCode('test-auto-link-' . uniqid());
        foreach ($names as $locale => $name) {
            $t = new GlobalIngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $ingredient->addTranslation($t);
        }
        foreach ($allergens as $code => $presence) {
            $link = new GlobalIngredientAllergen();
            $link->setAllergen($this->allergen($code));
            $link->setPresence($presence);
            $ingredient->addAllergenLink($link);
        }
        $this->em->persist($ingredient);
        $this->em->flush();
        $this->globalIngredientIds[] = $ingredient->getId();

        return $ingredient->getId();
    }

    private function allergen(string $code): Allergen
    {
        return $this->em->getRepository(Allergen::class)->findOneBy(['code' => $code]);
    }

    /** @param list<string> $typedNames */
    private function saveTyped(array $typedNames): void
    {
        $this->client->request(
            'POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'id' => $this->productId,
                'categoryId' => $this->categoryId,
                'basePrice' => 10.0,
                'translations' => ['es' => ['name' => 'Plato de test', 'description' => null]],
                'ingredients' => array_map(static fn (string $name) => ['value' => 'new:' . $name], $typedNames),
            ])
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * The product's ingredients in order, as "g:<id>" (library) or
     * "r:<es name>" (private) — read from a fresh EntityManager.
     *
     * @return list<string>
     */
    private function links(Product|int $product): array
    {
        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($product instanceof Product ? $product->getId() : $product);
        $links = [];
        foreach ($product->getIngredientLinks() as $link) {
            $links[$link->getPosition()] = 'r:' . $link->getIngredient()->getTranslation('es')?->getName();
        }
        foreach ($product->getGlobalIngredientLinks() as $link) {
            $links[$link->getPosition()] = 'g:' . $link->getGlobalIngredient()->getId();
        }
        ksort($links);

        return array_values($links);
    }

    public function testExactNameInTheMenuLanguageLinksTheLibraryIngredient(): void
    {
        $id = $this->globalIngredient(['en' => 'Testmilk ' . $this->u, 'es' => 'Testleche ' . $this->u], ['milk' => AllergenPresence::CONTAINS]);

        $this->saveTyped(['TESTLECHE ' . $this->u]);

        self::assertSame(['g:' . $id], $this->links($this->productId), 'case-insensitive exact match in es (the menu language) links');
    }

    public function testNameOnlyInAnotherLanguageCreatesAPrivateIngredient(): void
    {
        $this->globalIngredient(['en' => 'Testsalsa ' . $this->u, 'es' => 'Testsalsa mexicana ' . $this->u]);

        $this->saveTyped(['Testsalsa ' . $this->u]);

        self::assertSame(['r:Testsalsa ' . $this->u], $this->links($this->productId), 'an English-only match never links on an es menu');
    }

    public function testAnAmbiguousNameCreatesAPrivateIngredient(): void
    {
        $this->globalIngredient(['en' => 'Egg ' . $this->u, 'es' => 'Testhuevo ' . $this->u], ['eggs' => AllergenPresence::CONTAINS]);
        $this->globalIngredient(['en' => 'Chicken egg ' . $this->u, 'es' => 'Testhuevo ' . $this->u]);

        $this->saveTyped(['Testhuevo ' . $this->u]);

        self::assertSame(['r:Testhuevo ' . $this->u], $this->links($this->productId), 'two library ingredients share the name: neither is linked');
    }

    public function testTheImportFollowsTheSameRules(): void
    {
        $exact = $this->globalIngredient(['es' => 'Testleche ' . $this->u], ['milk' => AllergenPresence::CONTAINS]);
        $this->globalIngredient(['en' => 'Testsalsa ' . $this->u]);
        $this->globalIngredient(['en' => 'Egg ' . $this->u, 'es' => 'Testhuevo ' . $this->u], ['eggs' => AllergenPresence::CONTAINS]);
        $this->globalIngredient(['en' => 'Chicken egg ' . $this->u, 'es' => 'Testhuevo ' . $this->u]);

        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);
        $page = new MenuImportPage($batch, 'test.jpg', 0, hash('sha256', $this->u));
        $page->setStatus(MenuImportPageStatus::EXTRACTED);
        $page->setDetectedLocale('es');
        $page->setExtractedData([
            'detected_language' => 'es',
            'categories' => [[
                'name' => 'Entrantes ' . $this->u,
                'products' => [[
                    'name' => 'Plato importado',
                    'description' => null,
                    'price' => 9.5,
                    'ingredients' => [
                        ['name' => 'testleche ' . $this->u, 'uncertain' => false],
                        ['name' => 'Testsalsa ' . $this->u, 'uncertain' => false],
                        ['name' => 'Testhuevo ' . $this->u, 'uncertain' => false],
                    ],
                    'dietary_markers' => [],
                ]],
            ]],
        ]);
        $this->em->persist($page);
        $batch->addPage($page);
        $this->em->flush();

        static::getContainer()->get(MenuImportAssembler::class)->assemble($batch);

        $product = $this->em->getRepository(Product::class)->findOneBy(['importBatch' => $batch]);
        self::assertSame(['g:' . $exact, 'r:Testsalsa ' . $this->u, 'r:Testhuevo ' . $this->u], $this->links($product));
    }

    public function testNameLookupsPickTheOldestOnATie(): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        $ingredientIds = [];
        $categoryIds = [];
        foreach ([1, 2] as $_) {
            $ingredient = new Ingredient();
            $ingredient->setCode('test-auto-link-tie-' . uniqid());
            $ingredient->setRestaurant($restaurant);
            $t = new IngredientTranslation();
            $t->setLocale('es');
            $t->setName('Testempate ' . $this->u);
            $ingredient->addTranslation($t);
            $this->em->persist($ingredient);

            $category = new Category();
            $category->setRestaurant($restaurant);
            $ct = new CategoryTranslation();
            $ct->setLocale('es');
            $ct->setName('Testempate ' . $this->u);
            $category->addTranslation($ct);
            $this->em->persist($category);
            $this->em->persist($ct);
            $this->em->flush();
            $ingredientIds[] = $ingredient->getId();
            $categoryIds[] = $category->getId();
        }
        $this->em->clear();
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);

        self::assertSame(min($ingredientIds), static::getContainer()->get(IngredientRepository::class)->findExistingByNameAnyLocale($restaurant, 'testempate ' . $this->u)?->getId());
        self::assertSame(min($categoryIds), static::getContainer()->get(CategoryRepository::class)->findExistingByNameAnyLocale($restaurant, 'testempate ' . $this->u)?->getId());
    }
}
