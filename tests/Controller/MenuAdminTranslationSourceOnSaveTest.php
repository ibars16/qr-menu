<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Saving a dish marks a text as the owner's ('human') only when the owner
 * really changed it: saving with only the price (or anything else)
 * changed leaves it as it was — an AI text (say, one a main-language
 * change wrote) stays 'ai' — and redoes no translation. Through the real
 * routes: POST /admin/products/save and /admin/products/{id}/translations/{locale}.
 * Only the 20 menu languages are stored: any other code is ignored.
 */
final class MenuAdminTranslationSourceOnSaveTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $restaurantId;
    private int $categoryId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Translation Source On Save Test');
        $restaurant->setSlug('translation-source-save-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('en');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setRestaurant($restaurant);
        $this->em->persist($category);

        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice(1000);
        $this->em->persist($product);
        // A menu changed from Spanish to English: English by the AI, Spanish the owner's.
        foreach ([['en', 'Lamb tenderloin', 'ai'], ['es', 'Ternasco de cordero', 'human'], ['fr', 'Filet d\'agneau', 'ai'], ['it', 'Agnello da latte', 'human']] as [$locale, $name, $source]) {
            $t = new ProductTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $product->addTranslation($t);
            $this->em->persist($t);
        }

        $user = new User();
        $user->setEmail('translation-source-save-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->categoryId = $category->getId();
        $this->productId = $product->getId();
        $this->em->clear();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurantId);
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
        $this->em->flush();

        parent::tearDown();
    }

    private function save(string $name, float $price): void
    {
        $this->client->request('POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id'           => $this->productId,
            'categoryId'   => $this->categoryId,
            'basePrice'    => $price,
            'translations' => ['en' => ['name' => $name, 'description' => null]],
        ]));
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, array{0: string, 1: string}> locale => [name, source] */
    private function rows(): array
    {
        $this->em->clear();
        $rows = [];
        foreach ($this->em->find(Product::class, $this->productId)->getTranslations() as $t) {
            $rows[$t->getLocale()] = [$t->getName(), $t->getSource()];
        }
        ksort($rows);

        return $rows;
    }

    public function testOnlyThePriceChangedLeavesTheTextsAndTranslationsAlone(): void
    {
        $this->save('Lamb tenderloin', 12.5);

        self::assertSame([
            'en' => ['Lamb tenderloin', 'ai'],
            'es' => ['Ternasco de cordero', 'human'],
            'fr' => ['Filet d\'agneau', 'ai'],
            'it' => ['Agnello da latte', 'human'],
        ], $this->rows(), 'still the AI\'s text, nothing redone');
        self::assertSame(1250, $this->em->find(Product::class, $this->productId)->getBasePrice());
    }

    public function testChangedTextBecomesTheOwnersAndRedoesTheTranslations(): void
    {
        $this->save('Young lamb (ternasco)', 10.0);

        self::assertSame([
            'en' => ['Young lamb (ternasco)', 'human'],
            'es' => ['Ternasco de cordero', 'human'],
            'it' => ['Agnello da latte', 'human'],
        ], $this->rows(), 'the AI\'s French goes (made again from the new text); the owner\'s rows stay');
    }

    public function testHandCorrectionOnlyWhenTheTranslationChanged(): void
    {
        $url = '/admin/products/' . $this->productId . '/translations/fr';
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Filet d\'agneau', 'description' => '']));
        self::assertResponseIsSuccessful();
        self::assertSame(['Filet d\'agneau', 'ai'], $this->rows()['fr'], 'saved as it was: still the AI\'s');

        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Agneau de lait', 'description' => '']));
        self::assertResponseIsSuccessful();
        self::assertSame(['Agneau de lait', 'human'], $this->rows()['fr']);
    }

    public function testUnknownLanguageIsNeverStored(): void
    {
        $this->client->request('POST', '/admin/products/' . $this->productId . '/translations/xx', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Something', 'description' => '']));
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id'           => $this->productId,
            'categoryId'   => $this->categoryId,
            'basePrice'    => 10.0,
            'translations' => ['en' => ['name' => 'Lamb tenderloin', 'description' => null], 'xx' => ['name' => 'Something', 'description' => null]],
        ]));
        self::assertResponseIsSuccessful();

        self::assertSame(['en', 'es', 'fr', 'it'], array_keys($this->rows()), 'no "xx" row');
    }
}
