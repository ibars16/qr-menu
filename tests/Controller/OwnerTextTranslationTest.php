<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Entity\OwnerTextTranslation;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Service\ProductTranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Owner texts (tagline, multiprice labels, set-menu description and
 * sections) translated through OwnerTextTranslationService: only behind the
 * loading screen (POST warm-translations), one batch per locale, keyed by
 * source text. The AI is a fake ProductTranslatorInterface — no real call
 * can happen: every dish and category also has its 'de' translation, so
 * neither the dish nor the (real) category translator has anything to do.
 */
final class OwnerTextTranslationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?int $restaurantId = null;
    private string $slug;

    /** Records every batch it's asked for; answers "[de] <text>". */
    private object $fakeAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // Keep the fake translator across requests (a reboot would rebuild the container).
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->fakeAi = new class implements ProductTranslatorInterface {
            /** @var list<list<string>> */
            public array $batches = [];

            public function translate(array $products, string $targetLocale): array
            {
                $this->batches[] = array_values(array_map(static fn (array $p) => $p['name'], $products));

                return array_map(static fn (array $p) => ['name' => "[{$targetLocale}] " . $p['name'], 'description' => null], $products);
            }
        };
        static::getContainer()->set(ProductTranslatorInterface::class, $this->fakeAi);
    }

    protected function tearDown(): void
    {
        $restaurant = $this->restaurantId ? $this->em->getRepository(Restaurant::class)->find($this->restaurantId) : null;
        if ($restaurant) {
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush();
                foreach ($this->em->getRepository(MenuSection::class)->findBy(['category' => $category]) as $section) {
                    $this->em->remove($section);
                }
                $this->em->remove($category);
            }
            // owner_text_translation rows go with the restaurant (ON DELETE CASCADE).
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        parent::tearDown();
    }

    private function translate(object $owner, string $class, array $byLocale): void
    {
        foreach ($byLocale as $locale => $name) {
            $t = new $class();
            $t->setLocale($locale);
            $t->setName($name);
            $owner->addTranslation($t);
            $this->em->persist($t);
        }
    }

    private function product(Category $category, array $names, int $price, ?string $baseLabel = null, array $variants = []): Product
    {
        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice($price);
        $product->setBasePriceLabel($baseLabel);
        foreach ($variants as $i => [$label, $variantPrice]) {
            $variant = new ProductPriceVariant();
            $variant->setLabel($label);
            $variant->setPrice($variantPrice);
            $variant->setPosition($i);
            $product->addPriceVariant($variant);
        }
        $category->addProduct($product);
        $this->em->persist($product);
        $this->translate($product, ProductTranslation::class, $names);

        return $product;
    }

    /**
     * Tagline + two multiprice dishes sharing the label "Copa" + a set menu
     * with a description and a section. Distinct owner texts: 6.
     */
    private function makeRestaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Owner Text');
        $this->slug = 'owner-text-test-' . uniqid();
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $restaurant->setSetMenusEnabled(true);
        $restaurant->setTagline('Cocina de mercado.');
        $this->em->persist($restaurant);

        $wines = new Category();
        $wines->setActive(true);
        $restaurant->addCategory($wines);
        $this->em->persist($wines);
        $this->translate($wines, CategoryTranslation::class, ['es' => 'Vinos', 'de' => 'Weine']);
        $this->product($wines, ['es' => 'Rioja', 'de' => 'Rioja'], 1800, 'Botella', [['Copa', 450]]);
        $this->product($wines, ['es' => 'Albariño', 'de' => 'Albariño'], 2000, 'Botella', [['Copa', 500]]);

        $menu = new Category();
        $menu->setActive(true);
        $menu->setPosition(1);
        $menu->setMenuPrice(2500);
        $menu->setMenuDescription('Incluye pan y bebida.');
        $restaurant->addCategory($menu);
        $this->em->persist($menu);
        $this->translate($menu, CategoryTranslation::class, ['es' => 'Menú del día', 'de' => 'Tagesmenü']);
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $section->setPosition(0);
        $menu->addMenuSection($section);
        $this->em->persist($section);
        $dish = $this->product($menu, ['es' => 'Gazpacho', 'de' => 'Gazpacho'], 0);
        $section->addProduct($dish);

        $this->em->flush();
        $this->restaurantId = $restaurant->getId();

        return $restaurant;
    }

    private function warm(string $lang): void
    {
        $this->client->request('POST', '/r/' . $this->slug . '/warm-translations?lang=' . $lang);
        self::assertResponseIsSuccessful();
    }

    private function render(string $lang, bool $mtw = true): string
    {
        $this->client->request('GET', '/r/' . $this->slug . '?lang=' . $lang . '&currency=EUR' . ($mtw ? '&mtw=1' : ''));
        self::assertResponseIsSuccessful();

        return $this->client->getResponse()->getContent();
    }

    /** @return array<string, string> source text => translated text, 'de' rows of this restaurant */
    private function storedDe(): array
    {
        $rows = $this->em->getRepository(OwnerTextTranslation::class)->findBy(['restaurant' => $this->restaurantId, 'locale' => 'de']);

        return array_combine(
            array_map(static fn (OwnerTextTranslation $t) => $t->getSourceText(), $rows),
            array_map(static fn (OwnerTextTranslation $t) => $t->getTranslatedText(), $rows),
        );
    }

    private function queued(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }

    public function testWarmTranslatesEveryMissingTextInOneBatchAndStoresIt(): void
    {
        $this->makeRestaurant();
        $queued = $this->queued();

        $this->warm('de');

        self::assertCount(1, $this->fakeAi->batches, 'one AI batch for the locale');
        $batch = $this->fakeAi->batches[0];
        sort($batch);
        self::assertSame(['Botella', 'Cocina de mercado.', 'Copa', 'Incluye pan y bebida.', 'Primeros'], $batch, 'never the name or address');
        $stored = $this->storedDe();
        ksort($stored);
        self::assertSame([
            'Botella'               => '[de] Botella',
            'Cocina de mercado.'    => '[de] Cocina de mercado.',
            'Copa'                  => '[de] Copa',
            'Incluye pan y bebida.' => '[de] Incluye pan y bebida.',
            'Primeros'              => '[de] Primeros',
        ], $stored);
        self::assertSame($queued, $this->queued(), 'no queue involved');

        $this->warm('de');
        self::assertCount(1, $this->fakeAi->batches, 'nothing missing any more: no second batch');
    }

    public function testATextRepeatedAcrossDishesIsTranslatedAndStoredOnce(): void
    {
        $this->makeRestaurant();

        $this->warm('de');

        self::assertSame(1, count(array_keys($this->fakeAi->batches[0], 'Copa', true)), '"Copa" (two dishes) is sent once');
        self::assertSame(1, count(array_keys(array_keys($this->storedDe()), 'Copa', true)), 'and stored once');

        $html = $this->render('de');
        // Each dish prints its price stack twice (card front + detail).
        self::assertSame(4, substr_count($html, '<span class="price-item-label">[de] Copa</span>'), 'both dishes show the one translation');
        self::assertStringNotContainsString('<span lang="es">Copa</span>', $html);
    }

    public function testChangingATextAsksForAFreshTranslationOfThatTextOnly(): void
    {
        $this->makeRestaurant();
        $this->warm('de');

        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        $restaurant->setTagline('Cocina de temporada.');
        $this->em->flush();

        $this->warm('de');

        self::assertCount(2, $this->fakeAi->batches);
        self::assertSame(['Cocina de temporada.'], $this->fakeAi->batches[1], 'only the edited text');
        $html = $this->render('de');
        self::assertStringContainsString('<p class="tagline">[de] Cocina de temporada.</p>', $html);
        self::assertStringNotContainsString('[de] Cocina de mercado.', $html, 'the old translation is never shown');
    }

    public function testRenderingNeverCallsTheAiAndShowsTheOriginalMarkedAsSpanish(): void
    {
        $this->makeRestaurant();
        $queued = $this->queued();

        $html = $this->render('de');

        self::assertSame([], $this->fakeAi->batches, 'the menu render (mtw=1) never translates owner texts');
        self::assertSame([], $this->storedDe());
        self::assertSame($queued, $this->queued());
        self::assertStringContainsString('<p class="tagline"><span lang="es">Cocina de mercado.</span></p>', $html);
        self::assertStringContainsString('<span class="price-item-label"><span lang="es">Copa</span></span>', $html);
        self::assertStringContainsString('<div class="set-menu-desc"><span lang="es">Incluye pan y bebida.</span></div>', $html);
        self::assertStringContainsString('<div class="set-menu-group-label"><span lang="es">Primeros</span></div>', $html);
    }

    public function testAMissingOwnerTextSendsAFirstVisitThroughTheLoadingScreen(): void
    {
        $this->makeRestaurant();

        $html = $this->render('de', mtw: false);

        self::assertStringContainsString('warm-translations', $html, 'loading screen, which is where the AI runs');
        self::assertSame([], $this->fakeAi->batches, 'the loading screen itself translates nothing');
    }

    public function testTheSourceLanguageShowsThePlainText(): void
    {
        $this->makeRestaurant();

        $html = $this->render('es');

        self::assertStringContainsString('<p class="tagline">Cocina de mercado.</p>', $html);
        self::assertStringContainsString('<span class="price-item-label">Copa</span>', $html);
        self::assertStringNotContainsString('lang="es">', $html);
        self::assertSame([], $this->fakeAi->batches);
    }
}
