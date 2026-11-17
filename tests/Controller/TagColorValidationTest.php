<?php

namespace App\Tests\Controller;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A tag's color is printed inside style="…" on the Etiquetas screen, so
 * creating or editing a tag only accepts "#rrggbb" (Admin\TagsController).
 * No AI involved: creating a tag translates nothing.
 */
final class TagColorValidationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $restaurantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Tag Color Test');
        $restaurant->setSlug('tag-color-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $user = new User();
        $user->setEmail('tag-color-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurantId);
        $tags = $this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]);
        foreach ($tags as $tag) {
            foreach ($this->em->getRepository(ProductTagTranslation::class)->findBy(['tag' => $tag]) as $t) {
                $this->em->remove($t);
            }
            $this->em->remove($tag);
        }
        foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
            $this->em->remove($user);
        }
        $this->em->remove($restaurant);
        $this->em->flush();

        parent::tearDown();
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidColors(): iterable
    {
        yield 'css injection' => ['red;background:url(https://example.test/x)'];
        yield 'short hex' => ['#abc'];
        yield 'no hash' => ['2e7d32'];
        yield 'named color' => ['red'];
        yield 'not a string' => [123456];
    }

    #[DataProvider('invalidColors')]
    public function testCreatingATagWithAnInvalidColorIsRefused(mixed $color): void
    {
        $this->post('/admin/tags/create', ['name' => 'Picante', 'icon' => '🌶️', 'color' => $color]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('#rrggbb', $this->json()['error']);
        self::assertSame([], $this->tags(), 'nothing is created');
    }

    #[DataProvider('invalidColors')]
    public function testEditingATagWithAnInvalidColorIsRefused(mixed $color): void
    {
        $id = $this->createTag('#2E7D32');

        $this->post("/admin/tags/{$id}/edit", ['name' => 'Otro nombre', 'icon' => '🌶️', 'color' => $color]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame('#2E7D32', $this->em->find(ProductTag::class, $id)->getColor(), 'the color is kept');
    }

    public function testValidColorsAreSaved(): void
    {
        $id = $this->createTag('#2e7d32');
        $this->post("/admin/tags/{$id}/edit", ['name' => 'Picante', 'icon' => '🌶️', 'color' => '#A1B2C3']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame('#A1B2C3', $this->em->find(ProductTag::class, $id)->getColor());

        // Without a color (the product editor's "new tag"): the default / the current one.
        $this->post('/admin/tags/create', ['name' => 'Sin color', 'icon' => '🏷️']);
        self::assertResponseIsSuccessful();
        self::assertSame('#666666', $this->json()['color']);
        $this->post("/admin/tags/{$id}/edit", ['name' => 'Picante', 'icon' => '🌶️']);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame('#A1B2C3', $this->em->find(ProductTag::class, $id)->getColor());
    }

    private function createTag(string $color): int
    {
        $this->post('/admin/tags/create', ['name' => 'Picante ' . uniqid(), 'icon' => '🌶️', 'color' => $color]);
        self::assertResponseIsSuccessful();

        return (int) $this->json()['id'];
    }

    /** @param array<string, mixed> $data */
    private function post(string $url, array $data): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return list<ProductTag> */
    private function tags(): array
    {
        $this->em->clear();

        return $this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $this->restaurantId]);
    }
}
