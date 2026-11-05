<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\MenuSection;
use App\Entity\OwnerTextTranslation;
use App\Entity\Product;
use App\Entity\ProductPriceVariant;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\MenuImportBatchStatus;
use App\Service\CategoryTranslatorInterface;
use App\Service\MenuLanguageChanger;
use App\Service\MenuLanguageDetectorInterface;
use App\Service\ProductTranslationService;
use App\Service\ProductTranslatorInterface;
use App\Service\TagTranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Changing the menu's main language (MenuLanguageChanger): a) the texts
 * already are in the new language → relabelled, nothing translated;
 * b) they aren't (or a mix, or unknown) → converted batch by batch, the
 * owner's old rows kept (as translations), an existing AI translation
 * reused; blocked by an import still being read; resumed after a failure;
 * and an edit afterwards regenerates the old-language version. The AI is
 * faked: "[<locale>] <text>".
 */
final class MenuLanguageChangerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private Product $paella;
    private Product $wine;
    private Category $mains;
    private Category $setMenu;
    private ProductTag $tag;
    private Ingredient $ingredient;

    private object $detector;
    private object $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->detector = new class implements MenuLanguageDetectorInterface {
            public ?string $answer = 'es';
            public array $asked = [];

            public function detect(array $texts): ?string
            {
                $this->asked[] = $texts;

                return $this->answer;
            }

            /** Per text: text => code or null (unsure); any other text: English. */
            public array $languages = [];
            public array $checked = [];
            public bool $fail = false;

            public function detectEach(array $texts): ?array
            {
                if ($this->fail) {
                    return null;
                }
                $this->checked[] = $texts;

                return array_map(fn (string $t) => array_key_exists($t, $this->languages) ? $this->languages[$t] : 'en', $texts);
            }
        };
        // One fake for the three translators; records each call's texts.
        $this->ai = new class implements ProductTranslatorInterface, CategoryTranslatorInterface, TagTranslatorInterface {
            public array $calls = [];
            public bool $fail = false;

            public function translate(array $items, string $targetLocale, array $sourceLocales = []): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('rate limited');
                }
                $this->calls[] = array_values(array_map(static fn ($i) => is_array($i) ? $i['name'] : $i, $items));

                return array_map(static fn ($i) => is_array($i)
                    ? ['name' => "[{$targetLocale}] " . $i['name'], 'description' => $i['description'] !== null ? "[{$targetLocale}] " . $i['description'] : null]
                    : "[{$targetLocale}] " . $i, $items);
            }
        };
        $container = static::getContainer();
        $container->set(MenuLanguageDetectorInterface::class, $this->detector);
        $container->set(ProductTranslatorInterface::class, $this->ai);
        $container->set(CategoryTranslatorInterface::class, $this->ai);
        $container->set(TagTranslatorInterface::class, $this->ai);

        $this->makeMenu();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurant->getId());
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'language-change-test-%\'');
        $conn->executeStatement('DELETE FROM menu_import_batch WHERE restaurant_id = ' . (int) $restaurant->getId());
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
        foreach ($this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]) as $tag) {
            $this->em->remove($tag);
        }
        foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
            $this->em->remove($ingredient);
        }
        $this->em->flush();
        $this->em->remove($restaurant);
        $this->em->flush();

        parent::tearDown();
    }

    // ── Fixture ──

    private function translate(object $owner, string $class, array $byLocale, string $source = 'human'): void
    {
        foreach ($byLocale as $locale => $name) {
            $t = new $class();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $owner->addTranslation($t);
            $this->em->persist($t);
        }
    }

    /**
     * Spanish menu: Paella (+ description) and a wine with price labels
     * (Botella / Copa) in "Principales", an inactive imported dish, a set
     * menu with a description and a section, a custom tag, a private
     * ingredient and a tagline.
     */
    private function makeMenu(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Language Change Test');
        $restaurant->setSlug('language-change-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $restaurant->setTagline('Cocina de mercado.');
        $this->em->persist($restaurant);
        $this->restaurant = $restaurant;

        $this->mains = new Category();
        $this->mains->setActive(true);
        $restaurant->addCategory($this->mains);
        $this->em->persist($this->mains);
        $this->translate($this->mains, CategoryTranslation::class, ['es' => 'Principales']);

        $this->paella = $this->product($this->mains, 'Paella', 'Con arroz bomba');
        $this->wine = $this->product($this->mains, 'Vino tinto', null);
        $this->wine->setBasePriceLabel('Botella');
        $variant = new ProductPriceVariant();
        $variant->setLabel('Copa');
        $variant->setPrice(450);
        $variant->setPosition(0);
        $this->wine->addPriceVariant($variant);
        $this->product($this->mains, 'Croquetas', null)->setActive(false);

        $this->setMenu = new Category();
        $this->setMenu->setActive(true);
        $this->setMenu->setPosition(1);
        $restaurant->addCategory($this->setMenu);
        $this->em->persist($this->setMenu);
        $this->translate($this->setMenu, CategoryTranslation::class, ['es' => 'Menú del día']);
        $this->setMenu->setMenuDescription('Pan, bebida y postre');
        $section = new MenuSection();
        $section->setLabel('Primeros');
        $this->setMenu->addMenuSection($section);
        $this->em->persist($section);

        $this->tag = new ProductTag($restaurant, 'casero');
        $this->tag->setIcon('ti-home');
        $this->tag->setColor('#000000');
        $this->em->persist($this->tag);
        $this->translate($this->tag, ProductTagTranslation::class, ['es' => 'Casero']);

        $this->ingredient = new Ingredient();
        $this->ingredient->setCode('language-change-test-' . uniqid());
        $this->ingredient->setRestaurant($restaurant);
        $this->em->persist($this->ingredient);
        $t = new IngredientTranslation();
        $t->setLocale('es');
        $t->setName('Azafrán');
        $this->ingredient->addTranslation($t);
        $this->em->persist($t);

        $this->em->flush();
    }

    private function product(Category $category, string $name, ?string $description): Product
    {
        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice(1500);
        $category->addProduct($product);
        $this->em->persist($product);
        $t = new ProductTranslation();
        $t->setLocale('es');
        $t->setName($name);
        $t->setDescription($description);
        $product->addTranslation($t);
        $this->em->persist($t);

        return $product;
    }

    private function changer(): MenuLanguageChanger
    {
        return static::getContainer()->get(MenuLanguageChanger::class);
    }

    private function reloaded(): Restaurant
    {
        $this->em->clear();

        return $this->em->find(Restaurant::class, $this->restaurant->getId());
    }

    /** @return array<string, array{0: string, 1: string}> locale => [name, source] */
    private function rows(string $class, int $id): array
    {
        $rows = [];
        foreach ($this->em->find($class, $id)->getTranslations() as $t) {
            $rows[$t->getLocale()] = [$t->getName(), method_exists($t, 'getSource') ? $t->getSource() : '-'];
        }
        ksort($rows);

        return $rows;
    }

    /** Runs step() until done (at most 20 times). @return list<array> the results */
    private function convert(): array
    {
        $results = [];
        for ($i = 0; $i < 20; $i++) {
            $results[] = $result = $this->changer()->step($this->reloaded());
            if ($result['done'] || $result['error']) {
                break;
            }
        }

        return $results;
    }

    private function import(MenuImportBatchStatus $status, string $createdAt = 'now'): void
    {
        $batch = new MenuImportBatch($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $batch->setStatus($status);
        $this->em->persist($batch);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE menu_import_batch SET created_at = ? WHERE id = ?', [
            (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
            $batch->getId(),
        ]);
    }

    // ── a) Already in the new language ──

    public function testCaseAOnlyRelabelsTheOwnersTexts(): void
    {
        // An AI translation into English made from these texts: replaced by them.
        $this->translate($this->paella, ProductTranslation::class, ['en' => 'Paella (AI)', 'fr' => 'Paella (IA)'], 'ai');
        $this->em->flush();
        $this->detector->answer = 'en';

        self::assertSame(MenuLanguageChanger::DONE, $this->changer()->start($this->restaurant, 'en'));

        $restaurant = $this->reloaded();
        self::assertSame('en', $restaurant->getDefaultLanguage());
        self::assertNull($restaurant->getLanguageChangeTo());
        self::assertSame(['en' => ['Paella', 'human'], 'fr' => ['Paella (IA)', 'ai']], $this->rows(Product::class, $this->paella->getId()));
        self::assertSame('Con arroz bomba', $this->em->find(Product::class, $this->paella->getId())->getTranslation('en')->getDescription());
        self::assertSame(['en' => ['Principales', 'human']], $this->rows(Category::class, $this->mains->getId()));
        self::assertSame(['en' => ['Casero', 'human']], $this->rows(ProductTag::class, $this->tag->getId()));
        self::assertSame(['en' => ['Azafrán', '-']], $this->rows(Ingredient::class, $this->ingredient->getId()));
        self::assertSame('Cocina de mercado.', $restaurant->getTagline());
        self::assertSame([], $this->ai->calls, 'nothing translated');
        self::assertContains('Paella', $this->detector->asked[0]);
    }

    // ── b) Another language ──

    public function testCaseBWithoutTranslationsConvertsEverything(): void
    {
        self::assertSame(MenuLanguageChanger::CONVERTING, $this->changer()->start($this->restaurant, 'en'));
        $restaurant = $this->reloaded();
        self::assertSame('es', $restaurant->getDefaultLanguage(), 'kept until the conversion ends');
        self::assertSame('en', $restaurant->getLanguageChangeTo());

        $results = $this->convert();
        self::assertTrue(end($results)['done']);
        self::assertFalse(end($results)['error']);

        $restaurant = $this->reloaded();
        self::assertSame('en', $restaurant->getDefaultLanguage());
        self::assertNull($restaurant->getLanguageChangeTo());

        // The new text is the owner's; the old one stays, as a translation.
        self::assertSame(['en' => ['[en] Paella', 'human'], 'es' => ['Paella', 'ai']], $this->rows(Product::class, $this->paella->getId()));
        self::assertSame('[en] Con arroz bomba', $this->em->find(Product::class, $this->paella->getId())->getTranslation('en')->getDescription());
        self::assertNull($this->em->find(Product::class, $this->wine->getId())->getTranslation('en')->getDescription());
        self::assertSame(['en' => ['[en] Principales', 'human'], 'es' => ['Principales', 'ai']], $this->rows(Category::class, $this->mains->getId()));
        self::assertSame(['en' => ['[en] Casero', 'human'], 'es' => ['Casero', 'ai']], $this->rows(ProductTag::class, $this->tag->getId()));
        self::assertSame(['en' => ['[en] Azafrán', '-'], 'es' => ['Azafrán', '-']], $this->rows(Ingredient::class, $this->ingredient->getId()));

        // The inactive (imported, unpublished) dish too.
        $croquetas = array_values(array_filter(
            $this->em->find(Category::class, $this->mains->getId())->getProducts()->toArray(),
            static fn (Product $p) => !$p->isActive(),
        ))[0];
        self::assertSame('[en] Croquetas', $croquetas->getTranslation('en')->getName());

        // Free texts replaced; the originals are their Spanish translation.
        self::assertSame('[en] Cocina de mercado.', $restaurant->getTagline());
        $wine = $this->em->find(Product::class, $this->wine->getId());
        self::assertSame('[en] Botella', $wine->getBasePriceLabel());
        self::assertSame('[en] Copa', $wine->getPriceVariants()->first()->getLabel());
        $setMenu = $this->em->find(Category::class, $this->setMenu->getId());
        self::assertSame('[en] Pan, bebida y postre', $setMenu->getMenuDescription());
        self::assertSame('[en] Primeros', $setMenu->getMenuSections()->first()->getLabel());
        $back = $this->em->getRepository(OwnerTextTranslation::class)->findBy(['restaurant' => $restaurant, 'locale' => 'es']);
        $originals = [];
        foreach ($back as $row) {
            $originals[$row->getSourceText()] = [$row->getTranslatedText(), $row->getSourceHash(), $row->getOrigin()];
        }
        // Keyed as the render looks it up: the new language + the new text.
        self::assertSame(['Cocina de mercado.', OwnerTextTranslation::hash('en', '[en] Cocina de mercado.'), 'human'], $originals['[en] Cocina de mercado.']);
        self::assertSame(['Copa', OwnerTextTranslation::hash('en', '[en] Copa'), 'human'], $originals['[en] Copa']);
    }

    public function testCaseBReusesAnExistingTranslation(): void
    {
        $this->translate($this->paella, ProductTranslation::class, ['en' => 'Valencian paella'], 'ai');
        $this->em->flush();

        $this->changer()->start($this->restaurant, 'en');
        $this->convert();

        self::assertSame(['en' => ['Valencian paella', 'human'], 'es' => ['Paella', 'ai']], $this->rows(Product::class, $this->paella->getId()));
        self::assertNotContains('Paella', $this->ai->calls[0], 'not translated again');
        self::assertContains('Vino tinto', $this->ai->calls[0]);
    }

    /**
     * David Test's data: a Spanish menu entered while the label said
     * English, then relabelled Spanish without converting — its "en" rows
     * are the owner's, in Spanish. Those get translated and replaced;
     * a real English one and one the AI can't tell stay.
     */
    public function testCaseBReplacesNewLanguageRowsWrittenInAnotherLanguage(): void
    {
        $this->translate($this->paella, ProductTranslation::class, ['en' => 'Paella antigua']);
        $this->translate($this->wine, ProductTranslation::class, ['en' => 'Red wine']);
        $this->translate($this->mains, CategoryTranslation::class, ['en' => 'Principales']);
        $this->translate($this->tag, ProductTagTranslation::class, ['en' => '']);
        $pizza = $this->product($this->mains, 'Pizza margarita', null);
        $this->translate($pizza, ProductTranslation::class, ['en' => 'Pizza margarita']);
        $this->em->flush();
        $this->detector->languages = ['Paella antigua' => 'es', 'Principales' => 'es', 'Pizza margarita' => null];

        self::assertSame(MenuLanguageChanger::CONVERTING, $this->changer()->start($this->restaurant, 'en'));
        $results = $this->convert();
        self::assertTrue(end($results)['done']);

        // Checked once, every one in one batch (the blank tag needs no asking).
        self::assertCount(1, $this->detector->checked);
        self::assertEqualsCanonicalizing(['Paella antigua', 'Red wine', 'Principales', 'Pizza margarita'], array_values($this->detector->checked[0]));

        // Spanish under "en": translated from the main language's text, replaced.
        self::assertSame(['en' => ['[en] Paella', 'human'], 'es' => ['Paella', 'ai']], $this->rows(Product::class, $this->paella->getId()));
        self::assertSame('[en] Con arroz bomba', $this->em->find(Product::class, $this->paella->getId())->getTranslation('en')->getDescription());
        self::assertSame(['en' => ['[en] Principales', 'human'], 'es' => ['Principales', 'ai']], $this->rows(Category::class, $this->mains->getId()));
        self::assertSame(['en' => ['[en] Casero', 'human'], 'es' => ['Casero', 'ai']], $this->rows(ProductTag::class, $this->tag->getId()));
        // Really English, or can't tell: kept.
        self::assertSame(['en' => ['Red wine', 'human'], 'es' => ['Vino tinto', 'ai']], $this->rows(Product::class, $this->wine->getId()));
        self::assertSame(['en' => ['Pizza margarita', 'human'], 'es' => ['Pizza margarita', 'ai']], $this->rows(Product::class, $pizza->getId()));
        foreach ($this->ai->calls as $call) {
            self::assertNotContains('Vino tinto', $call);
            self::assertNotContains('Pizza margarita', $call);
        }
        self::assertSame('en', $this->reloaded()->getDefaultLanguage());
    }

    /**
     * David Test's dish 5874: its Spanish (main) name is empty, its "en"
     * one is set. Nothing is translated (not from another language
     * either) and the "en" row stays as it is.
     */
    public function testBlankMainTextLeavesTheNewLanguageRowAlone(): void
    {
        $salmon = $this->product($this->mains, '', null);
        $this->translate($salmon, ProductTranslation::class, ['en' => 'Carpaccio de salmón', 'fr' => 'Carpaccio de saumon'], 'human');
        $empty = $this->product($this->mains, '', null);
        $this->translate($empty, ProductTranslation::class, ['fr' => 'Saumon fumé'], 'ai');
        $this->em->flush();
        $this->detector->languages = ['Carpaccio de salmón' => 'es'];

        $this->changer()->start($this->restaurant, 'en');
        $results = $this->convert();
        self::assertTrue(end($results)['done']);

        self::assertSame(['en' => ['Carpaccio de salmón', 'human'], 'es' => ['', 'ai'], 'fr' => ['Carpaccio de saumon', 'human']], $this->rows(Product::class, $salmon->getId()));
        self::assertSame(['es' => ['', 'ai'], 'fr' => ['Saumon fumé', 'ai']], $this->rows(Product::class, $empty->getId()));
        foreach ($this->detector->checked as $batch) {
            self::assertNotContains('Carpaccio de salmón', $batch, 'not even checked');
        }
        foreach ($this->ai->calls as $call) {
            self::assertNotContains('Carpaccio de saumon', $call);
            self::assertNotContains('Saumon fumé', $call);
        }
    }

    public function testFailedLanguageCheckIsRetried(): void
    {
        $this->translate($this->paella, ProductTranslation::class, ['en' => 'Paella antigua']);
        $this->em->flush();
        $this->detector->languages = ['Paella antigua' => 'es'];
        $this->changer()->start($this->restaurant, 'en');

        $this->detector->fail = true;
        $result = $this->changer()->step($this->reloaded());
        self::assertTrue($result['error']);
        self::assertSame(['en' => ['Paella antigua', 'human'], 'es' => ['Paella', 'human']], $this->rows(Product::class, $this->paella->getId()));
        self::assertSame([], $this->ai->calls, 'nothing translated unchecked');

        $this->detector->fail = false;
        $results = $this->convert();
        self::assertTrue(end($results)['done']);
        self::assertSame(['en' => ['[en] Paella', 'human'], 'es' => ['Paella', 'ai']], $this->rows(Product::class, $this->paella->getId()));
    }

    public function testMixOrUnknownLanguageIsConverted(): void
    {
        foreach ([MenuLanguageDetectorInterface::MIXED, null] as $answer) {
            $this->detector->answer = $answer;
            self::assertSame(MenuLanguageChanger::CONVERTING, $this->changer()->start($this->reloaded(), 'en'));
            $restaurant = $this->reloaded();
            self::assertSame('es', $restaurant->getDefaultLanguage());
            $restaurant->setLanguageChangeTo(null);
            $this->em->flush();
        }
    }

    public function testEmptyMenuChangesAtOnce(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Language Change Test Empty');
        $restaurant->setSlug('language-change-test-empty-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);
        $this->em->flush();

        try {
            self::assertSame(MenuLanguageChanger::DONE, $this->changer()->start($restaurant, 'fr'));
            self::assertSame('fr', $restaurant->getDefaultLanguage());
            self::assertSame([], $this->detector->asked);
        } finally {
            $this->em->remove($restaurant);
            $this->em->flush();
        }
    }

    // ── Pending import ──

    public function testImportBeingReadBlocksTheChange(): void
    {
        $this->import(MenuImportBatchStatus::PROCESSING);

        self::assertSame(MenuLanguageChanger::BLOCKED_IMPORT, $this->changer()->start($this->reloaded(), 'en'));
        $restaurant = $this->reloaded();
        self::assertSame('es', $restaurant->getDefaultLanguage());
        self::assertNull($restaurant->getLanguageChangeTo());
        self::assertSame([], $this->detector->asked);
    }

    public function testOldOrReviewableImportDoesNotBlock(): void
    {
        $this->import(MenuImportBatchStatus::UPLOADED, '-25 hours');
        $this->import(MenuImportBatchStatus::READY_FOR_REVIEW);

        self::assertSame(MenuLanguageChanger::CONVERTING, $this->changer()->start($this->reloaded(), 'en'));
    }

    // ── Resume ──

    public function testFailureKeepsTheChangeHalfDoneAndItResumes(): void
    {
        $this->changer()->start($this->restaurant, 'en');

        $this->ai->fail = true;
        $result = $this->changer()->step($this->reloaded());
        self::assertSame(['done' => false, 'error' => true], ['done' => $result['done'], 'error' => $result['error']]);
        $restaurant = $this->reloaded();
        self::assertSame('es', $restaurant->getDefaultLanguage());
        self::assertSame('en', $restaurant->getLanguageChangeTo());
        self::assertSame(['es' => ['Paella', 'human']], $this->rows(Product::class, $this->paella->getId()));

        // Later (another window, another day): from where it was.
        $this->ai->fail = false;
        $results = $this->convert();
        self::assertTrue(end($results)['done']);
        self::assertSame('en', $this->reloaded()->getDefaultLanguage());
        self::assertSame(['en' => ['[en] Paella', 'human'], 'es' => ['Paella', 'ai']], $this->rows(Product::class, $this->paella->getId()));
    }

    // ── Editing afterwards ──

    public function testEditAfterTheChangeRegeneratesTheOldLanguageButKeepsHandCorrections(): void
    {
        $this->translate($this->paella, ProductTranslation::class, ['fr' => 'Paëlla maison'], 'human');
        $this->em->flush();
        $this->changer()->start($this->restaurant, 'en');
        $this->convert();

        // What MenuAdminController::saveProduct() does when the English text changes.
        $product = $this->em->find(Product::class, $this->paella->getId());
        $product->getTranslation('en')->setName('Seafood paella');
        static::getContainer()->get(ProductTranslationService::class)->invalidateStale($product, 'en');
        $this->em->flush();

        self::assertSame(['en' => ['Seafood paella', 'human'], 'fr' => ['Paëlla maison', 'human']], $this->rows(Product::class, $this->paella->getId()));
    }

    // ── Panel ──

    private function owner(): User
    {
        $user = new User();
        $user->setEmail('language-change-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function saveLanguage(string $language): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/admin/settings', [
            '_token'          => $crawler->filter('input[name="_token"]')->attr('value'),
            'section'         => 'language',
            'defaultLanguage' => $language,
            'adminLocale'     => 'es',
            'currency'        => 'EUR',
        ]);
    }

    public function testSettingsConvertsThroughTheScreen(): void
    {
        $this->client->loginUser($this->owner());
        $this->saveLanguage('en');

        self::assertResponseRedirects('/admin/idioma/convirtiendo');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('inglés', $crawler->filter('.language-change-text')->text());

        // Closed meanwhile: Configuración offers to continue, the field is locked.
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertSelectorTextContains('#language-change-pending', 'El cambio de tu carta al inglés está a medias.');
        self::assertNotNull($crawler->filter('#defaultLanguage')->attr('disabled'));

        $token = $this->client->request('GET', '/admin/idioma/convirtiendo')->filter('script')->reduce(
            static fn ($s) => str_contains($s->text(), 'stepUrl'),
        )->text();
        preg_match('/const token\s*=\s*"([^"]+)"/', $token, $m);
        for ($i = 0; $i < 20; $i++) {
            $this->client->request('POST', '/admin/idioma/convertir', ['_token' => $m[1]]);
            self::assertResponseIsSuccessful();
            $json = json_decode($this->client->getResponse()->getContent(), true);
            if ($json['done']) {
                break;
            }
        }
        self::assertTrue($json['done']);

        $this->client->request('GET', $json['next']);
        self::assertSelectorTextContains('.flash-success', 'Tu carta ahora está en inglés. Revisa los textos.');
        self::assertSelectorNotExists('#language-change-pending');
        self::assertSame('en', $this->reloaded()->getDefaultLanguage());
    }

    public function testSettingsCaseAChangesAtOnce(): void
    {
        $this->detector->answer = 'en';
        $this->client->loginUser($this->owner());
        $this->saveLanguage('en');

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Tu carta ahora está en inglés. Revisa los textos.');
        self::assertSame('en', $this->reloaded()->getDefaultLanguage());
    }

    public function testSettingsBlockedByAnImport(): void
    {
        $this->import(MenuImportBatchStatus::UPLOADED);
        $this->client->loginUser($this->owner());
        $this->saveLanguage('en');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Termina la importación antes de cambiar el idioma');
        self::assertSame('es', $this->reloaded()->getDefaultLanguage());
    }

    public function testStepNeedsItsToken(): void
    {
        $this->client->loginUser($this->owner());
        $this->changer()->start($this->reloaded(), 'en');

        $this->client->request('POST', '/admin/idioma/convertir', ['_token' => 'bogus']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('es', $this->reloaded()->getDefaultLanguage());
    }
}
