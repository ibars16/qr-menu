<?php

namespace App\Tests\Controller;

use App\Controller\Admin\ThemeController;
use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductAllergenOverride;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "¿Qué necesitas?" filters panel: the one allergy section is the allergen
 * list computed from ingredients ("Alergias", "Ocultar platos que
 * contengan…"); "contains-…" tags, the older dairy/nuts/contains_gluten
 * codes and "recommended" are never offered as chips, owner tags still are.
 * Every dish and tag is translated into es so the render needs no AI call
 * and no loading screen. Runs against qrmenu_test.
 */
final class MenuFiltersPanelTest extends WebTestCase
{
    private const TAGS = [
        // code => [isSystem, es name]
        'vegetarian'      => [true, 'Vegetariano'],
        'spicy'           => [true, 'Picante'],
        'contains-dairy'  => [true, 'Contiene lácteos'],
        'recommended'     => [true, 'Nuestra recomendación'],
        'dairy'           => [true, 'Lácteos'],
        'nuts'            => [true, 'Frutos secos'],
        'contains_gluten' => [true, 'Gluten'],
        'de-temporada'    => [false, 'De temporada'],
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?int $restaurantId = null;
    /** @var array<string, int> */
    private array $tagIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $restaurant = $this->restaurantId ? $this->em->getRepository(Restaurant::class)->find($this->restaurantId) : null;
        if ($restaurant) {
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
            }
            $this->em->flush();
            foreach ($this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]) as $tag) {
                $this->em->remove($tag);
            }
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        parent::tearDown();
    }

    private function allergen(string $code): Allergen
    {
        return $this->em->getRepository(Allergen::class)->findOneBy(['code' => $code]);
    }

    private function makeRestaurant(string $theme): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Filtros');
        $restaurant->setSlug('menu-filters-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme($theme);
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);
        $ct = new CategoryTranslation();
        $ct->setLocale('es');
        $ct->setName('Entrantes');
        $category->addTranslation($ct);
        $this->em->persist($ct);

        $tags = [];
        foreach (self::TAGS as $code => [$isSystem, $name]) {
            $tag = new ProductTag($restaurant, $code, $isSystem);
            $tt = new ProductTagTranslation();
            $tt->setLocale('es');
            $tt->setName($name);
            $tag->addTranslation($tt);
            $this->em->persist($tt);
            $this->em->persist($tag);
            $tags[$code] = $tag;
        }

        // Croquetas carries every tag and contains milk; Ensalada is marked
        // free from gluten (an override) and carries nothing else.
        $dishes = [
            'Croquetas' => [array_values($tags), ['milk' => AllergenPresence::CONTAINS]],
            'Ensalada'  => [[], ['gluten' => AllergenPresence::FREE_FROM]],
        ];
        $i = 0;
        foreach ($dishes as $dishName => [$dishTags, $overrides]) {
            $product = new Product();
            $product->setBasePrice(1250);
            $product->setActive(true);
            $product->setPosition($i++);
            $category->addProduct($product);
            foreach ($dishTags as $tag) {
                $product->addTag($tag);
            }
            $this->em->persist($product);

            $pt = new ProductTranslation();
            $pt->setLocale('es');
            $pt->setName($dishName);
            $pt->setDescription('…');
            $product->addTranslation($pt);
            $this->em->persist($pt);

            foreach ($overrides as $code => $presence) {
                $override = new ProductAllergenOverride();
                $override->setAllergen($this->allergen($code));
                $override->setPresence($presence);
                $product->addAllergenOverride($override);
                $this->em->persist($override);
            }
        }

        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        foreach ($tags as $code => $tag) {
            $this->tagIds[$code] = $tag->getId();
        }
        $this->em->clear();

        return $restaurant;
    }

    private function render(Restaurant $restaurant): Crawler
    {
        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es&currency=EUR&mtw=1');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Section title without maison's count span. */
    private static function titles(Crawler $panel): array
    {
        return $panel->filter('.filters-section-title')->each(fn (Crawler $n) => trim($n->getNode(0)->firstChild->nodeValue));
    }

    /** @return iterable<string, array{string}> Every theme: they all share the same markup. */
    public static function themes(): iterable
    {
        foreach (array_keys(ThemeController::THEMES) as $theme) {
            yield $theme => [$theme];
        }
    }

    #[DataProvider('themes')]
    public function testOneAllergySectionComputedFromIngredients(string $theme): void
    {
        $crawler = $this->render($this->makeRestaurant($theme));
        $panel = $crawler->filter('#filters-overlay');
        self::assertCount(1, $panel);

        self::assertSame(['Dietas', 'Alergias', 'Otros'], self::titles($panel));
        self::assertStringNotContainsString('Evitar alérgenos', $crawler->html());

        $allergy = $panel->filter('.filters-section')->eq(1);
        self::assertSame('Ocultar platos que contengan…', trim($allergy->filter('.filters-section-sub')->text()));
        $codes = $allergy->filter('[data-filter="allergen"]')->each(fn (Crawler $n) => $n->attr('data-allergen'));
        self::assertSame(['milk'], $codes, '"Sin gluten" is not something to avoid');
    }

    #[DataProvider('themes')]
    public function testOnlyRealFilterTagsAreOfferedAsChips(string $theme): void
    {
        $panel = $this->render($this->makeRestaurant($theme))->filter('#filters-overlay');

        $chipIds = $panel->filter('[data-filter="diet"]:not([data-tag="all"])')->each(fn (Crawler $n) => (int) $n->attr('data-tag'));
        $expected = [$this->tagIds['vegetarian'], $this->tagIds['spicy'], $this->tagIds['de-temporada']];
        sort($chipIds);
        sort($expected);
        self::assertSame($expected, $chipIds, 'no contains-…, legacy allergen or "recommended" chips; the owner tag stays');
    }

    public function testFreeFromEntriesNeverCountAsAnAllergenToAvoid(): void
    {
        $crawler = $this->render($this->makeRestaurant('maison'));

        $salad = $crawler->filter('.dish-card')->reduce(fn (Crawler $n) => str_contains($n->text(), 'Ensalada'));
        self::assertSame('gluten:free_from', trim($salad->attr('data-allergens')), 'still on the card for its chip');
        self::assertStringContainsString("!a.endsWith(':free_from')", $crawler->html(), 'the filter predicate skips it');
    }
}
