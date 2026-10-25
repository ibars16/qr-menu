<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Saving a dish from the editor (POST /admin/products/save) keeps its
 * visibility: the editor used to send `active: true` on every save, so
 * editing a hidden dish put it back on the public menu. Visibility is
 * changed only by its own toggle (POST /admin/products/{id}/toggle); a new
 * dish is created visible.
 */
final class MenuAdminProductVisibilityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Category $category;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Product Visibility Test Restaurant');
        $restaurant->setSlug('product-visibility-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        $this->category = new Category();
        $this->category->setRestaurant($restaurant);
        $this->em->persist($this->category);
        $this->em->flush();

        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('product-visibility-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
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

    private function makeProduct(bool $active): int
    {
        $product = new Product();
        $product->setCategory($this->category);
        $product->setBasePrice(1000);
        $product->setActive($active);
        $this->em->persist($product);
        $this->em->flush();
        $id = $product->getId();
        // See MenuAdminNutritionFieldsTest: the next request must reload, not reuse, this entity.
        $this->em->clear();

        return $id;
    }

    /** @param array<string, mixed> $extra */
    private function save(?int $id, array $extra = []): int
    {
        $this->client->request(
            'POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(array_merge([
                'id' => $id,
                'categoryId' => $this->category->getId(),
                'basePrice' => 12.5,
                'translations' => ['es' => ['name' => 'Plato de prueba', 'description' => null]],
            ], $extra))
        );
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true)['id'];
    }

    private function isActive(int $id): bool
    {
        $this->em->clear();

        return $this->em->getRepository(Product::class)->find($id)->isActive();
    }

    public function testEditingAHiddenDishKeepsItHidden(): void
    {
        $id = $this->makeProduct(active: false);

        $this->save($id);

        self::assertFalse($this->isActive($id), 'saving from the editor must not put a hidden dish back on the menu');
    }

    public function testAnOldEditorStillSendingActiveTrueDoesNotUnhideTheDish(): void
    {
        // Exactly what the editor sent before the fix (a tab left open on
        // the old JS keeps sending it until reloaded).
        $id = $this->makeProduct(active: false);

        $this->save($id, ['active' => true]);

        self::assertFalse($this->isActive($id));
    }

    public function testEditingAVisibleDishKeepsItVisible(): void
    {
        $id = $this->makeProduct(active: true);

        $this->save($id, ['active' => false]);

        self::assertTrue($this->isActive($id), 'the editor never hides a dish either — that is the toggle\'s job');
    }

    public function testANewDishIsCreatedVisible(): void
    {
        $id = $this->save(null);

        self::assertTrue($this->isActive($id));
    }

    public function testTheEditorNoLongerSendsAVisibilityFlag(): void
    {
        $js = file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/templates/admin/_product_js.html.twig');
        $payload = substr($js, strpos($js, 'const payload = {'), 4000);
        $payload = substr($payload, 0, strpos($payload, "\n        };") ?: \strlen($payload));

        self::assertDoesNotMatchRegularExpression('/^\s*active\s*:/m', $payload);
    }
}
