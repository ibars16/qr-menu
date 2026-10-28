<?php

namespace App\Tests\Controller;

use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The ingredient autocomplete. The library is searched in the panel
 * language, the menu language, en and es — AI-translated names included —
 * and shows each ingredient once, in the panel language or else in English.
 * The restaurant's own ingredients are searched in the panel and menu
 * languages, shown in the panel language or else by the name that matched.
 *
 * Here the panel is French and the menu Italian. Every name carries a
 * uniqid() so the test database's own library rows can never match.
 */
final class IngredientAutocompleteLocalesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $u;

    private ?int $restaurantId = null;
    /** @var int[] */
    private array $globalIngredientIds = [];
    /** @var int[] */
    private array $ownIngredientIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->u = uniqid();

        $restaurant = new Restaurant();
        $restaurant->setName('Casa Autocompletado');
        $restaurant->setSlug('autocomplete-locales-test-' . $this->u);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('it');
        $restaurant->setAdminLocale('fr');
        $this->em->persist($restaurant);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('autocomplete-locales-test-' . $this->u . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
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
        $restaurant = $this->restaurantId ? $this->em->getRepository(Restaurant::class)->find($this->restaurantId) : null;
        if ($restaurant) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
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

    /** @param array<string, array{string, string}> $names locale => [name, source] */
    private function globalIngredient(array $names): int
    {
        $ingredient = new GlobalIngredient();
        $ingredient->setCode('test-autocomplete-' . uniqid());
        foreach ($names as $locale => [$name, $source]) {
            $t = new GlobalIngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $ingredient->addTranslation($t);
        }
        $this->em->persist($ingredient);
        $this->em->flush();
        $this->globalIngredientIds[] = $ingredient->getId();

        return $ingredient->getId();
    }

    /** @param array<string, string> $names locale => name */
    private function ownIngredient(array $names): int
    {
        $ingredient = new Ingredient();
        $ingredient->setCode('test-autocomplete-own-' . uniqid());
        $ingredient->setRestaurant($this->em->getRepository(Restaurant::class)->find($this->restaurantId));
        foreach ($names as $locale => $name) {
            $t = new IngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $ingredient->addTranslation($t);
        }
        $this->em->persist($ingredient);
        $this->em->flush();
        $this->ownIngredientIds[] = $ingredient->getId();

        return $ingredient->getId();
    }

    /** @return array<string, string> "g:<id>" / "r:<id>" => shown name, only for this test's ingredients */
    private function search(string $query): array
    {
        $this->client->request('GET', '/admin/ingredients/search', ['q' => $query]);
        self::assertResponseIsSuccessful();

        $ours = [
            ...array_map(static fn (int $id) => 'g:' . $id, $this->globalIngredientIds),
            ...array_map(static fn (int $id) => 'r:' . $id, $this->ownIngredientIds),
        ];
        $results = [];
        foreach (json_decode($this->client->getResponse()->getContent(), true) as $row) {
            if (in_array($row['value'], $ours, true)) {
                self::assertArrayNotHasKey($row['value'], $results, 'each ingredient appears once');
                $results[$row['value']] = $row['name'];
            }
        }

        return $results;
    }

    public function testAnyOfTheFourLanguagesFindsItShownInThePanelLanguage(): void
    {
        $d = GlobalIngredientTranslation::SOURCE_DATASET;
        $id = $this->globalIngredient([
            'en' => ['Zqtomato ' . $this->u, $d],
            'es' => ['Zqtomate rojo ' . $this->u, $d],
            'it' => ['Zqpomodoro ' . $this->u, $d],
            'fr' => ['Zqtomate fr ' . $this->u, GlobalIngredientTranslation::SOURCE_AI],
        ]);

        foreach (['Zqtomato', 'Zqtomate rojo', 'Zqpomodoro', 'Zqtomate fr'] as $query) {
            self::assertSame(['g:' . $id => 'Zqtomate fr ' . $this->u], $this->search($query . ' ' . $this->u), "\"{$query}\" finds it, shown in French (an AI name)");
        }
    }

    public function testWithoutAPanelLanguageNameItIsShownInEnglish(): void
    {
        $d = GlobalIngredientTranslation::SOURCE_DATASET;
        $id = $this->globalIngredient([
            'en' => ['Zqbasil ' . $this->u, $d],
            'it' => ['Zqbasilico ' . $this->u, $d],
        ]);

        self::assertSame(['g:' . $id => 'Zqbasil ' . $this->u], $this->search('Zqbasilico ' . $this->u));
    }

    public function testOtherLanguagesAreNotSearched(): void
    {
        $d = GlobalIngredientTranslation::SOURCE_DATASET;
        $this->globalIngredient([
            'en' => ['Zqpotato ' . $this->u, $d],
            'de' => ['Zqkartoffel ' . $this->u, $d],
        ]);

        // Without the shared uniqid suffix, which alone would make the
        // fuzzy (trigram) tier match the English name.
        self::assertSame([], $this->search('Zqkartoffel'), 'German is neither the panel, the menu, en nor es');
    }

    public function testOwnIngredientsAreFoundInTheMenuLanguageToo(): void
    {
        $onlyItalian = $this->ownIngredient(['it' => 'Zqsugo ' . $this->u]);
        $both = $this->ownIngredient(['it' => 'Zqragu ' . $this->u, 'fr' => 'Zqragoût ' . $this->u]);

        // Checked one entry at a time: the shared uniqid suffix alone lets
        // the fuzzy (trigram) tier match the other ingredient as well.
        self::assertSame('Zqsugo ' . $this->u, $this->search('Zqsugo ' . $this->u)['r:' . $onlyItalian] ?? null, 'found by its menu-language name, shown by it');
        self::assertSame('Zqragoût ' . $this->u, $this->search('Zqragu ' . $this->u)['r:' . $both] ?? null, 'found by its menu-language name, shown in the panel language');
        self::assertSame('Zqragoût ' . $this->u, $this->search('Zqragoût ' . $this->u)['r:' . $both] ?? null);
    }

    public function testOwnIngredientsInOtherLanguagesAreNotSearched(): void
    {
        $this->ownIngredient(['de' => 'Zqsosse ' . $this->u]);

        self::assertSame([], $this->search('Zqsosse'), 'German is neither the panel nor the menu language');
    }
}
