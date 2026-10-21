<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Service\MenuPreferencesResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Public menu text direction: <html lang dir> comes from config/languages.php
 * ('dir' => 'rtl' on ar only) via menu_dir(), and the RTL-only pieces (Arabic
 * font link, mirrored chevrons/arrow, <span dir="auto"> around owner text)
 * appear on ar and nowhere else. Every dish/category/tag is translated into
 * es and ar on purpose: a missing dish translation is filled by a real,
 * synchronous AI call during the render (ProductTranslationService), and a
 * missing tag sends a first visit through the loading screen. Two 'recommended' dishes
 * give maison its banner carousel; the tag also makes the filters button
 * render.
 */
final class MenuTextDirectionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?int $restaurantId = null;

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

    private function makeRestaurant(string $theme): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Dir');
        $restaurant->setSlug('menu-dir-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme($theme);
        $restaurant->setLayout('standard');
        $restaurant->setTagline('Cocina de mercado.');
        $restaurant->setAddress('Calle Mayor 1, Madrid');
        $this->em->persist($restaurant);

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);

        foreach (['es' => 'Entrantes', 'ar' => 'المقبلات'] as $locale => $categoryName) {
            $ct = new CategoryTranslation();
            $ct->setLocale($locale);
            $ct->setName($categoryName);
            $category->addTranslation($ct);
            $this->em->persist($ct);
        }

        $tag = new ProductTag($restaurant, 'recommended', true);
        foreach (['es' => 'Recomendado', 'ar' => 'موصى به'] as $locale => $name) {
            $tt = new ProductTagTranslation();
            $tt->setLocale($locale);
            $tt->setName($name);
            $tag->addTranslation($tt);
            $this->em->persist($tt);
        }
        $this->em->persist($tag);

        foreach ([['es' => 'Croquetas', 'ar' => 'كروكيت'], ['es' => 'Pulpo', 'ar' => 'أخطبوط']] as $i => $names) {
            $product = new Product();
            $product->setBasePrice(1250);
            $product->setSpicyLevel($i === 0 ? 5 : 0);
            $product->setImage('dir-test.jpg');
            $product->setActive(true);
            $product->setPosition($i);
            $category->addProduct($product);
            $product->addTag($tag);
            $this->em->persist($product);

            foreach ($names as $locale => $dishName) {
                $pt = new ProductTranslation();
                $pt->setLocale($locale);
                $pt->setName($dishName);
                $pt->setDescription('…');
                $product->addTranslation($pt);
                $this->em->persist($pt);
            }
        }

        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $this->em->clear();

        return $restaurant;
    }

    private function render(Restaurant $restaurant, string $lang): string
    {
        $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=' . $lang . '&currency=EUR&mtw=1');
        self::assertResponseIsSuccessful();

        return $this->client->getResponse()->getContent();
    }

    public function testOnlyArabicIsMarkedRightToLeftInTheLanguageConfig(): void
    {
        $resolver = static::getContainer()->get(MenuPreferencesResolver::class);

        $rtl = array_keys(array_filter($resolver->getLanguages(), fn (string $code) => $resolver->getTextDirection($code) === 'rtl', ARRAY_FILTER_USE_KEY));
        self::assertSame(['ar'], $rtl);
        self::assertSame('ltr', $resolver->getTextDirection('xx'), 'unknown code');
    }

    /** @return iterable<string, array{string}> */
    public static function themes(): iterable
    {
        yield 'maison' => ['maison'];
        yield 'classic-dark' => ['classic-dark'];
    }

    #[DataProvider('themes')]
    public function testArabicRendersRtlWithItsFontAndMirroredIcons(string $theme): void
    {
        $restaurant = $this->makeRestaurant($theme);
        $queued = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');

        $html = $this->render($restaurant, 'ar');

        self::assertStringContainsString('<html lang="ar" dir="rtl">', $html);
        // Spice wording that can't be read as temperature ("درجة الحرارة"
        // was) — pending native review, see menu_public.ar.yaml.
        self::assertStringContainsString('مستوى التوابل الحارة', $html);
        self::assertStringContainsString('ناري', $html);
        self::assertStringNotContainsString('درجة الحرارة', $html);
        self::assertStringContainsString('family=IBM+Plex+Sans+Arabic', $html);
        self::assertSame($theme === 'maison', str_contains($html, 'family=Noto+Naskh+Arabic'), 'Naskh is maison-only');
        self::assertMatchesRegularExpression('#class="more-hint">[^<]*<i class="ti ti-chevron-left"#u', $html, '"Ver más" points the reading way');
        self::assertDoesNotMatchRegularExpression('#class="more-hint">[^<]*<i class="ti ti-chevron-right"#u', $html);
        // Not translated (nothing behind the loading screen ran): the
        // original, marked as Spanish and with its own direction.
        self::assertStringContainsString('<p class="ft-tagline"><span lang="es" dir="auto">Cocina de mercado.</span></p>', $html);
        self::assertStringContainsString('<span dir="auto">Calle Mayor 1, Madrid</span>', $html);
        if ($theme === 'maison') {
            self::assertStringContainsString('<span class="allergy-arrow" aria-hidden="true">←</span>', $html);
            // Carousel: "back" points right, "next" points left.
            self::assertMatchesRegularExpression('#banner-prev"[^>]*><i class="ti ti-chevron-right"#', $html);
            self::assertMatchesRegularExpression('#banner-next"[^>]*><i class="ti ti-chevron-left"#', $html);
        }
        self::assertSame($queued, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'), 'rendering ar queued nothing');
    }

    #[DataProvider('themes')]
    public function testSpanishStaysLtrWithoutAnyRtlOnlyMarkup(string $theme): void
    {
        $html = $this->render($this->makeRestaurant($theme), 'es');

        self::assertStringContainsString('<html lang="es" dir="ltr">', $html);
        self::assertStringNotContainsString('IBM+Plex+Sans+Arabic', $html);
        self::assertStringNotContainsString('<bdi', $html);
        self::assertStringNotContainsString('dir="auto"', $html);
        self::assertMatchesRegularExpression('#class="more-hint">[^<]*<i class="ti ti-chevron-right"#u', $html);
        self::assertStringContainsString('<p class="ft-tagline">Cocina de mercado.</p>', $html);
        if ($theme === 'maison') {
            self::assertStringContainsString('<span class="allergy-arrow" aria-hidden="true">→</span>', $html);
            self::assertMatchesRegularExpression('#banner-prev"[^>]*><i class="ti ti-chevron-left"#', $html);
            self::assertMatchesRegularExpression('#banner-next"[^>]*><i class="ti ti-chevron-right"#', $html);
        }
    }
}
