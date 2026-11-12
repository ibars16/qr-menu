<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /admin/categories/{id}/translations/save, the same rule as a dish's
 * texts: a name the owner really changed becomes theirs ('human'), one
 * saved as it was stays as it was (an AI name stays 'ai'), and only the 20
 * menu languages are stored. No AI is called: a changed main-language name
 * only drops the AI translations (made again on the next loading screen).
 */
final class CategoryTranslationsSaveTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $restaurantId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Category Translations Save Test');
        $restaurant->setSlug('category-translations-save-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setRestaurant($restaurant);
        $this->em->persist($category);
        foreach ([['es', 'Postres', 'human'], ['fr', 'Desserts', 'ai'], ['it', 'Dolci', 'human']] as [$locale, $name, $source]) {
            $t = new CategoryTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $category->addTranslation($t);
            $this->em->persist($t);
        }

        $user = new User();
        $user->setEmail('category-translations-save-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->categoryId = $category->getId();
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
            $this->em->remove($category);
        }
        $this->em->remove($restaurant);
        $this->em->flush();

        parent::tearDown();
    }

    /** @param array<string, string> $translations */
    private function save(array $translations): void
    {
        $this->client->request('POST', '/admin/categories/' . $this->categoryId . '/translations/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['translations' => $translations]));
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, array{0: string, 1: string}> locale => [name, source] */
    private function rows(): array
    {
        $this->em->clear();
        $rows = [];
        foreach ($this->em->find(Category::class, $this->categoryId)->getTranslations() as $t) {
            $rows[$t->getLocale()] = [$t->getName(), $t->getSource()];
        }
        ksort($rows);

        return $rows;
    }

    public function testSavedAsItWasStaysTheAis(): void
    {
        $this->save(['fr' => 'Desserts', 'es' => 'Postres']);

        self::assertSame([
            'es' => ['Postres', 'human'],
            'fr' => ['Desserts', 'ai'],
            'it' => ['Dolci', 'human'],
        ], $this->rows());
    }

    public function testChangedTextBecomesTheOwners(): void
    {
        $this->save(['fr' => 'Douceurs']);

        self::assertSame(['Douceurs', 'human'], $this->rows()['fr']);
    }

    public function testNewLanguageIsTheOwners(): void
    {
        $this->save(['de' => 'Nachspeisen']);

        self::assertSame(['Nachspeisen', 'human'], $this->rows()['de']);
    }

    public function testChangedMainNameDropsTheAiTranslationsOnly(): void
    {
        $this->save(['es' => 'Dulces']);

        self::assertSame([
            'es' => ['Dulces', 'human'],
            'it' => ['Dolci', 'human'],
        ], $this->rows(), 'the AI\'s French goes (made again from the new name); the owner\'s Italian stays');
    }

    public function testUnknownLanguageIsNeverStored(): void
    {
        $this->save(['xx' => 'Something', 'fr' => 'Douceurs']);

        self::assertSame(['es', 'fr', 'it'], array_keys($this->rows()), 'no "xx" row');
        self::assertSame(['Douceurs', 'human'], $this->rows()['fr'], 'the valid one still saved');
    }
}
