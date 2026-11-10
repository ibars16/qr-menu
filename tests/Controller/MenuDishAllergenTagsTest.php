<?php

namespace App\Tests\Controller;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\Product;
use App\Entity\ProductAllergenOverride;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The dish detail's "Etiquetas" (normal dishes and set-menu dishes): the
 * "contains-…" system tags come from the dish's allergens, never from what
 * was ticked (ProductEffectiveTags) — "contiene" shows the tag, "puede
 * contener" doesn't, and a ticked one without the allergen is gone. Every
 * name is in es so the render needs no AI call. Runs against qrmenu_test.
 */
final class MenuDishAllergenTagsTest extends WebTestCase
{
    private const TAGS = [
        // code => [isSystem, es name]
        'contains-gluten' => [true, 'Contiene gluten'],
        'contains-dairy'  => [true, 'Contiene lácteos'],
        'contains-nuts'   => [true, 'Contiene frutos secos'],
        'vegetarian'      => [true, 'Vegetariano'],
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
                $this->em->flush(); // dishes first: they reference their section
                foreach ($this->em->getRepository(MenuSection::class)->findBy(['category' => $category]) as $section) {
                    $this->em->remove($section);
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

    private function category(Restaurant $restaurant, string $name, ?int $menuPrice = null): Category
    {
        $category = new Category();
        $category->setActive(true);
        if ($menuPrice !== null) {
            $category->setMenuPrice($menuPrice);
        }
        $restaurant->addCategory($category);
        $this->em->persist($category);
        $ct = new CategoryTranslation();
        $ct->setLocale('es');
        $ct->setName($name);
        $category->addTranslation($ct);
        $this->em->persist($ct);

        return $category;
    }

    /**
     * @param ProductTag[]                      $tags      ticked by hand
     * @param array<string, AllergenPresence>   $overrides allergen code => presence (the dish's effective allergens)
     */
    private function dish(Category $category, string $name, array $tags, array $overrides, ?MenuSection $section = null): void
    {
        $product = new Product();
        $product->setBasePrice($section ? 0 : 1250);
        $product->setActive(true);
        $product->setPosition($category->getProducts()->count());
        $category->addProduct($product);
        $section?->addProduct($product);
        foreach ($tags as $tag) {
            $product->addTag($tag);
        }
        $this->em->persist($product);

        $pt = new ProductTranslation();
        $pt->setLocale('es');
        $pt->setName($name);
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

    private function render(): Crawler
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Etiquetas');
        $restaurant->setSlug('dish-allergen-tags-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $restaurant->setSetMenusEnabled(true);
        $this->em->persist($restaurant);

        $tags = [];
        foreach (self::TAGS as $code => [$isSystem, $tagName]) {
            $tag = new ProductTag($restaurant, $code, $isSystem);
            $tt = new ProductTagTranslation();
            $tt->setLocale('es');
            $tt->setName($tagName);
            $tag->addTranslation($tt);
            $restaurant->addProductTag($tag);
            $this->em->persist($tt);
            $this->em->persist($tag);
            $tags[$code] = $tag;
        }

        $carta = $this->category($restaurant, 'Entrantes');
        $this->dish($carta, 'Pan con tomate', [], ['gluten' => AllergenPresence::CONTAINS]);
        $this->dish($carta, 'Carpaccio', [$tags['contains-nuts'], $tags['vegetarian']], []);
        $this->dish($carta, 'Flan', [$tags['contains-dairy']], ['milk' => AllergenPresence::MAY_CONTAIN]);

        $menu = $this->category($restaurant, 'Menú del día', 1500);
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $section->setPosition(0);
        $menu->addMenuSection($section);
        $this->em->persist($section);
        $this->dish($menu, 'Sopa de pasta', [], ['gluten' => AllergenPresence::CONTAINS], $section);

        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        foreach ($tags as $code => $tag) {
            $this->tagIds[$code] = $tag->getId();
        }
        $this->em->clear();

        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es&currency=EUR&mtw=1');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return string[] the "Etiquetas" names in one dish's detail (none when the section isn't there) */
    private static function detailTags(Crawler $dish): array
    {
        $section = $dish->filter('.bsec')->reduce(fn (Crawler $n) => str_starts_with(trim($n->filter('.bsec-title')->text()), 'Etiquetas'));
        if ($section->count() === 0) {
            return [];
        }

        // The fixture tags have no icon, so a chip's text is just its name.
        return $section->filter('.lbl-list > *')->each(fn (Crawler $n) => trim($n->text()));
    }

    private static function card(Crawler $crawler, string $selector, string $name): Crawler
    {
        $card = $crawler->filter($selector)->reduce(fn (Crawler $n) => str_contains($n->text(), $name));
        self::assertCount(1, $card, $name);

        return $card;
    }

    public function testContainsShowsTheTagThoughNobodyTickedIt(): void
    {
        $crawler = $this->render();
        $card = self::card($crawler, '.dish-card', 'Pan con tomate');

        self::assertSame(['Contiene gluten'], self::detailTags($card));
        self::assertSame((string) $this->tagIds['contains-gluten'], trim($card->attr('data-tags')));
    }

    public function testATickedContainsTagWithoutTheAllergenIsGone(): void
    {
        $card = self::card($this->render(), '.dish-card', 'Carpaccio');

        self::assertSame(['Vegetariano'], self::detailTags($card), 'the owner\'s other tags stay');
        self::assertSame((string) $this->tagIds['vegetarian'], trim($card->attr('data-tags')));
    }

    public function testMayContainNeverShowsTheTag(): void
    {
        $card = self::card($this->render(), '.dish-card', 'Flan');

        self::assertSame([], self::detailTags($card), 'no "Etiquetas" section at all: ticked contains-dairy ignored, may_contain adds nothing');
        self::assertSame('', trim($card->attr('data-tags')));
    }

    public function testSetMenuDishesFollowTheSameRule(): void
    {
        $row = self::card($this->render(), '.set-menu-row-wrap', 'Sopa de pasta');

        self::assertSame(['Contiene gluten'], self::detailTags($row));
        self::assertSame((string) $this->tagIds['contains-gluten'], trim($row->attr('data-tags')));
    }
}
