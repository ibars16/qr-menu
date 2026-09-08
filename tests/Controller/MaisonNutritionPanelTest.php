<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fase 3 of the nutrition-fields project (see project memory): the public
 * menu's dish-detail modal shows a maison-only "Información Nutricional"
 * panel (calories + fat/protein/carbohydrates/sugars), scoped in
 * standard/show.html.twig via `{% if theme == 'maison' %}` — the non-maison
 * branch is byte-identical to the pre-existing calories-only block.
 *
 * The one behavior this suite exists to pin down precisely: fat/protein/
 * carbohydrates/sugars are Doctrine decimal(5,1) strings, kept as strings
 * end to end (never cast to float) so a real fractional gram like "6.5" is
 * never mangled. The public menu trims a trailing ".0" for display only
 * ("20.0" -> "20") — this must never touch what's actually stored: the
 * database/getter must still return "20.0" after a page that displays "20".
 */
final class MaisonNutritionPanelTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if (!$restaurant) {
                continue;
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

    /**
     * @param array{calories?: int, fat?: string, protein?: string, carbohydrates?: string, sugars?: string} $nutrition
     */
    private function makeProduct(string $theme, string $dishName, array $nutrition): Product
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Nutrition Panel Test');
        $restaurant->setSlug('nutrition-panel-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme($theme);
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category); // both-sides — see MenuContentCacheTest's own note on why setRestaurant() alone isn't enough here
        $this->em->persist($category);

        $categoryTranslation = new CategoryTranslation();
        $categoryTranslation->setLocale('es');
        $categoryTranslation->setName('Categoría de prueba');
        $category->addTranslation($categoryTranslation);
        $this->em->persist($categoryTranslation);

        $product = new Product();
        $product->setBasePrice(1000);
        $product->setActive(true);
        if (array_key_exists('calories', $nutrition)) {
            $product->setCalories($nutrition['calories']);
        }
        if (array_key_exists('fat', $nutrition)) {
            $product->setFat($nutrition['fat']);
        }
        if (array_key_exists('protein', $nutrition)) {
            $product->setProtein($nutrition['protein']);
        }
        if (array_key_exists('carbohydrates', $nutrition)) {
            $product->setCarbohydrates($nutrition['carbohydrates']);
        }
        if (array_key_exists('sugars', $nutrition)) {
            $product->setSugars($nutrition['sugars']);
        }
        $category->addProduct($product);
        $this->em->persist($product);

        $productTranslation = new ProductTranslation();
        $productTranslation->setLocale('es');
        $productTranslation->setName($dishName);
        $product->addTranslation($productTranslation);
        $this->em->persist($productTranslation);

        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $product;
    }

    public function testFullPanelRendersAllFiveFieldsWithTrimmedDecimalsButStorageStaysExact(): void
    {
        $product = $this->makeProduct('maison', 'Plato Completo', [
            'calories' => 500,
            'fat' => '54.0',
            'protein' => '77.0',
            'carbohydrates' => '34.0',
            'sugars' => '6.5', // deliberately fractional — must NOT be trimmed
        ]);

        $crawler = $this->client->request('GET', '/r/' . $product->getCategory()->getRestaurant()->getSlug() . '?lang=es');
        self::assertResponseIsSuccessful();

        $bsec = $crawler->filter('.nutrition-grid');
        self::assertCount(1, $bsec, 'expected exactly one nutrition panel in the response');
        $text = $bsec->text();

        self::assertStringContainsString('Información Nutricional', $crawler->filter('.bsec-title')->reduce(
            fn ($n) => str_contains($n->text(), 'Nutricional')
        )->text());
        self::assertStringContainsString('500', $text);
        self::assertStringContainsString('kcal', $text);
        self::assertStringContainsString('54', $text);
        self::assertStringNotContainsString('54.0', $text, 'a whole-number gram value must be trimmed for display ("54", not "54.0")');
        self::assertStringContainsString('77', $text);
        self::assertStringContainsString('34', $text);
        self::assertStringContainsString('6.5', $text, 'a real fractional gram value must be printed byte-for-byte, never trimmed');

        // The critical check: displaying "54" must never have touched what's
        // actually stored. Re-read from a fresh EntityManager, not the
        // in-memory entity built in makeProduct().
        $this->em->clear();
        $fresh = $this->em->getRepository(Product::class)->find($product->getId());
        self::assertSame('54.0', $fresh->getFat(), 'storage must keep the full decimal(5,1) string even though the page displayed "54"');
        self::assertSame('77.0', $fresh->getProtein());
        self::assertSame('34.0', $fresh->getCarbohydrates());
        self::assertSame('6.5', $fresh->getSugars());
        self::assertIsString($fresh->getFat(), 'the getter must still return a string, never a float');
    }

    public function testPartialDataOnlyRendersTheFieldsThatAreSet(): void
    {
        $product = $this->makeProduct('maison', 'Plato Parcial', [
            'calories' => 500,
            'sugars' => '0.0', // a real, affirmative zero — must render, not be treated as "no data"
        ]);

        $crawler = $this->client->request('GET', '/r/' . $product->getCategory()->getRestaurant()->getSlug() . '?lang=es');
        self::assertResponseIsSuccessful();

        $items = $crawler->filter('.nutrition-item');
        self::assertCount(2, $items, 'only the two fields actually set (calories, sugars) should render, no empty placeholders for the other three');

        $text = $crawler->filter('.nutrition-grid')->text();
        self::assertStringContainsString('Calorías', $text);
        self::assertStringContainsString('Azúcares', $text);
        self::assertStringContainsString('0', $text, 'a real 0.0g must render as an affirmative value, not be hidden as "no data"');
        self::assertStringNotContainsString('Grasas', $text);
        self::assertStringNotContainsString('Proteínas', $text);
        self::assertStringNotContainsString('Carbohidratos', $text);
    }

    public function testNoNutritionDataRendersNoPanelAndNoTitleAtAll(): void
    {
        $product = $this->makeProduct('maison', 'Plato Sin Datos', []);

        $crawler = $this->client->request('GET', '/r/' . $product->getCategory()->getRestaurant()->getSlug() . '?lang=es');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('.nutrition-grid'), 'no panel body when all five fields are empty');

        // Scoped to actual .bsec-title element text, not the raw response —
        // the CSS comment documenting this panel (overrides.html.twig,
        // inlined in a <style> block) legitimately mentions the phrase
        // "Información Nutricional" too, which a raw-string search would
        // false-positive on.
        $titles = $crawler->filter('.bsec-title')->each(fn ($n) => trim($n->text()));
        self::assertNotContains('Información Nutricional', $titles, 'the section title must not render either — not even an empty-bodied section');
    }

    public function testNonMaisonThemeIsCompletelyUnaffected(): void
    {
        // Same data as the full-panel maison test above, but on classic-dark
        // — must render exactly like it always has: calories-only, plain
        // .bsec/.nut markup, no trace of the new panel classes anywhere.
        $product = $this->makeProduct('classic-dark', 'Plato No Maison', [
            'calories' => 320,
            'fat' => '54.0',
            'protein' => '77.0',
            'carbohydrates' => '34.0',
            'sugars' => '6.5',
        ]);

        $crawler = $this->client->request('GET', '/r/' . $product->getCategory()->getRestaurant()->getSlug() . '?lang=es');
        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();

        self::assertStringContainsString('320', $content);
        self::assertStringContainsString('kcal', $content);
        self::assertCount(0, $crawler->filter('.nutrition-grid'), 'non-maison themes must never render the new panel, even when fat/protein/carbohydrates/sugars are set');
        self::assertStringNotContainsString('nutrition-item', $content);
        self::assertStringNotContainsString('nutrition-label', $content);
        // The pre-existing calories markup must be untouched: one .bsec whose
        // .nut-v/.nut-u pair is the calories value, nothing wrapping it in
        // the new per-item structure.
        self::assertMatchesRegularExpression(
            '/<div class="bsec"><div class="bsec-title">[^<]*<\/div><div class="nut"><span class="nut-v">320<\/span><span class="nut-u">kcal<\/span><\/div><\/div>/',
            $content,
            'the shared calories block markup must be byte-for-byte the same shape as before this feature'
        );
    }
}
