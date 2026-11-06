<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\MenuImportBatchStatus;
use App\Enum\MenuImportPageStatus;
use App\Service\CategoryTranslatorInterface;
use App\Service\IndependentReviewTranslator;
use App\Service\MenuImportLanguageNotice;
use App\Service\MenuImportPipeline;
use App\Service\MenuLanguageDetectorInterface;
use App\Service\ProductTranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "⚠ Esta carta parece estar en {idioma}…" (MenuImportLanguageNotice): an
 * import read in another language than the menu's main one gets a notice on
 * Carta — only when the AI is sure (≥ 5 sure answers, ≥ 70 % one language);
 * a doubt or a failure: no notice. "Traducir" translates, batch by batch,
 * only this import's dishes and the categories it created that are still
 * pending review — never what the owner edited or published, a category it
 * reused, ingredients, prices — keeping the text read as the owner's in its
 * real language. A failed batch writes nothing; "Dejarla así" changes
 * nothing. The imports go through the real pipeline (pages already
 * extracted); the AI is faked: "[<locale>] <text>".
 */
final class MenuImportLanguageNoticeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private Category $starters;
    private object $detector;
    private object $ai;

    /** @var int[] */
    private array $restaurantIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->detector = new class implements MenuLanguageDetectorInterface {
            /** text => code (null: unsure); any other text: $default */
            public array $languages = [];
            public ?string $default = 'en';
            public bool $fail = false;
            public bool $throw = false;
            public array $checked = [];

            public function detect(array $texts): ?string
            {
                throw new \LogicException('not used by the import check');
            }

            public function detectEach(array $texts): ?array
            {
                if ($this->throw) {
                    throw new \RuntimeException('provider down');
                }
                if ($this->fail) {
                    return null;
                }
                $this->checked[] = $texts;

                return array_map(fn (string $t) => array_key_exists($t, $this->languages) ? $this->languages[$t] : $this->default, $texts);
            }
        };
        $this->ai = new class implements ProductTranslatorInterface, CategoryTranslatorInterface, IndependentReviewTranslator {
            public array $calls = [];
            public bool $fail = false;
            /** names never answered (the AI leaves them out) */
            public array $skip = [];

            public function withIndependentReview(): static
            {
                return $this;
            }

            public function translate(array $items, string $targetLocale, array $sourceLocales = []): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('rate limited');
                }
                $this->calls[] = ['names' => array_values(array_map(static fn ($i) => is_array($i) ? $i['name'] : $i, $items)), 'from' => array_values(array_unique($sourceLocales)), 'to' => $targetLocale];
                $out = [];
                foreach ($items as $key => $i) {
                    $name = is_array($i) ? $i['name'] : $i;
                    if (in_array($name, $this->skip, true)) {
                        continue;
                    }
                    $out[$key] = is_array($i)
                        ? ['name' => "[{$targetLocale}] " . $i['name'], 'description' => $i['description'] !== null ? "[{$targetLocale}] " . $i['description'] : null]
                        : "[{$targetLocale}] " . $i;
                }

                return $out;
            }
        };
        $container = static::getContainer();
        $container->set(MenuLanguageDetectorInterface::class, $this->detector);
        $container->set(ProductTranslatorInterface::class, $this->ai);
        $container->set(CategoryTranslatorInterface::class, $this->ai);

        $this->restaurant = $this->makeRestaurant();
        $this->client->loginUser($this->makeOwner($this->restaurant));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        foreach ($this->restaurantIds as $id) {
            $restaurant = $this->em->find(Restaurant::class, $id);
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush();
                $this->em->remove($category);
            }
            foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
                $this->em->remove($ingredient);
            }
            $this->em->flush();
            $conn->executeStatement('DELETE FROM menu_import_batch WHERE restaurant_id = ' . (int) $id);
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        parent::tearDown();
    }

    // ── Fixture ──

    private function makeRestaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Import Language Test');
        $restaurant->setSlug('import-language-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        // An existing category the import reuses (same name).
        $starters = new Category();
        $starters->setRestaurant($restaurant);
        $t = new CategoryTranslation();
        $t->setLocale('es');
        $t->setName('Starters');
        $starters->addTranslation($t);
        $this->em->persist($starters);
        $this->em->persist($t);
        $restaurant->getCategories()->add($starters);
        $this->starters = $starters;

        $this->em->flush();
        $this->restaurantIds[] = $restaurant->getId();

        return $restaurant;
    }

    private function makeOwner(Restaurant $restaurant): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('import-language-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * An English menu photo, already read: "Starters" (reused) and "Mains"
     * (created), 6 dishes (+ $extraDishes in Mains), run through the real
     * pipeline (assembly + the language check).
     */
    private function import(int $extraDishes = 0, ?Restaurant $restaurant = null): MenuImportBatch
    {
        $restaurant = $this->em->find(Restaurant::class, ($restaurant ?? $this->restaurant)->getId());
        $dish = static fn (string $name, ?string $description = null, array $ingredients = []) => [
            'name' => $name, 'description' => $description, 'price' => 12.5,
            'ingredients' => array_map(static fn (string $i) => ['name' => $i], $ingredients),
        ];
        $mains = [
            $dish('Grilled salmon', 'With lemon butter', ['lemon zest']),
            $dish('Beef burger', null, ['pickled onion']),
            $dish('Fish and chips'),
            $dish('Chicken wings'),
        ];
        for ($i = 1; $i <= $extraDishes; $i++) {
            $mains[] = $dish('Daily special ' . $i);
        }

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);
        $page = new MenuImportPage($batch, 'test.jpg', 0, hash('sha256', uniqid()));
        $page->setStatus(MenuImportPageStatus::EXTRACTED);
        $page->setExtractedData(['categories' => [
            ['name' => 'Starters', 'products' => [$dish('Caesar salad'), $dish('Onion rings')]],
            ['name' => 'Mains', 'products' => $mains],
        ]]);
        $this->em->persist($page);
        $batch->addPage($page);
        $this->em->flush();

        static::getContainer()->get(MenuImportPipeline::class)->processBatch($batch);

        return $batch;
    }

    private function dish(string $readName): Product
    {
        foreach ($this->em->getRepository(Product::class)->findAll() as $product) {
            foreach ($product->getTranslations() as $t) {
                if ($t->getName() === $readName && $product->getCategory()->getRestaurant()->getId() === $this->restaurant->getId()) {
                    return $product;
                }
            }
        }
        $this->fail("No dish named {$readName}");
    }

    private function category(string $readName): Category
    {
        foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $this->restaurant->getId()]) as $category) {
            foreach ($category->getTranslations() as $t) {
                if ($t->getName() === $readName) {
                    return $category;
                }
            }
        }
        $this->fail("No category named {$readName}");
    }

    /** @return array<string, array{0: string, 1: string}> locale => [name, source] */
    private function texts(Product|Category $entity): array
    {
        $texts = [];
        foreach ($entity->getTranslations() as $t) {
            $texts[$t->getLocale()] = [$t->getName(), $t->getSource()];
        }
        ksort($texts);

        return $texts;
    }

    private function step(MenuImportBatch $batch, ?string $token = null): array
    {
        $this->client->request('POST', '/admin/menu/import/' . $batch->getId() . '/language/translate', [
            '_token' => $token ?? $this->token($batch),
        ]);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function token(MenuImportBatch $batch): string
    {
        $this->client->request('GET', '/admin/menu');
        $crawler = $this->client->getCrawler()->filter('.import-lang-notice[data-step-url$="/' . $batch->getId() . '/language/translate"]');
        $this->assertCount(1, $crawler, 'the notice is on Carta');

        return $crawler->attr('data-token');
    }

    private function carta(): string
    {
        $this->client->request('GET', '/admin/menu');
        $this->assertResponseIsSuccessful();

        return $this->client->getResponse()->getContent();
    }

    private function batch(MenuImportBatch $batch): MenuImportBatch
    {
        $this->em->clear();

        return $this->em->find(MenuImportBatch::class, $batch->getId());
    }

    // ── The check ──

    public function testNoticeWhenTheImportIsClearlyInAnotherLanguage(): void
    {
        $batch = $this->batch($this->import());

        $this->assertSame(MenuImportBatchStatus::READY_FOR_REVIEW, $batch->getStatus());
        $this->assertSame('en', $batch->getDetectedLanguage());
        $this->assertSame(MenuImportBatch::NOTICE_PENDING, $batch->getLanguageNotice());
        $this->assertCount(1, $this->detector->checked, 'one AI call');
        $this->assertNotContains('Starters', $this->detector->checked[0], 'a reused category is not the import\'s');

        $html = $this->carta();
        $this->assertStringContainsString('Esta carta parece estar en inglés y tu carta está en español: 6 platos.', $html);
        $this->assertStringContainsString('Traducir al español', $html);
        $this->assertStringContainsString('Dejarla así', $html);
    }

    public function testNoNoticeWhenTheImportIsInTheMainLanguage(): void
    {
        $this->detector->default = 'es';
        $batch = $this->batch($this->import());

        $this->assertNull($batch->getLanguageNotice());
        $this->assertStringNotContainsString('import-lang-notice"', $this->carta());
    }

    /** @return iterable<string, array{0: array<string, ?string>}> */
    public static function unsureAnswers(): iterable
    {
        // 7 names: Caesar salad, Onion rings, Grilled salmon, Beef burger, Fish and chips, Chicken wings, Mains.
        yield 'only 4 sure' => [['Caesar salad' => null, 'Onion rings' => null, 'Fish and chips' => null]];
        yield 'under 70 % one language' => [['Caesar salad' => 'it', 'Onion rings' => 'it', 'Beef burger' => 'it']];
    }

    #[DataProvider('unsureAnswers')]
    public function testNoNoticeWhenTheAiIsUnsure(array $languages): void
    {
        $this->detector->languages = $languages;
        $batch = $this->batch($this->import());

        $this->assertNull($batch->getLanguageNotice());
        $this->assertNull($batch->getDetectedLanguage());
    }

    public function testVerdictThresholds(): void
    {
        $this->assertSame('en', MenuImportLanguageNotice::verdict(['a' => 'en', 'b' => 'en', 'c' => 'en', 'd' => 'en', 'e' => 'en']));
        $this->assertNull(MenuImportLanguageNotice::verdict(['a' => 'en', 'b' => 'en', 'c' => 'en', 'd' => 'en', 'e' => null]), '4 sure');
        $this->assertSame('en', MenuImportLanguageNotice::verdict(['a' => 'en', 'b' => 'en', 'c' => 'en', 'd' => 'en', 'e' => 'en', 'f' => 'en', 'g' => 'en', 'h' => 'fr', 'i' => 'fr', 'j' => 'fr']), '70 %');
        $this->assertNull(MenuImportLanguageNotice::verdict(['a' => 'en', 'b' => 'en', 'c' => 'en', 'd' => 'en', 'e' => 'en', 'f' => 'en', 'g' => 'fr', 'h' => 'fr', 'i' => 'fr']), '66 %');
    }

    public function testNoNoticeWhenTheAiFails(): void
    {
        $this->detector->fail = true;
        $batch = $this->batch($this->import());
        $this->assertSame(MenuImportBatchStatus::READY_FOR_REVIEW, $batch->getStatus());
        $this->assertNull($batch->getLanguageNotice());

        $this->detector->fail = false;
        $this->detector->throw = true;
        $batch = $this->batch($this->import());
        $this->assertSame(MenuImportBatchStatus::READY_FOR_REVIEW, $batch->getStatus(), 'the import is never stopped by the check');
        $this->assertNull($batch->getLanguageNotice());
    }

    // ── Traducir ──

    public function testTranslatesOnlyWhatIsStillTheImportsUntouchedText(): void
    {
        $batch = $this->import();
        $ingredientsBefore = $this->em->getConnection()->fetchFirstColumn(
            'SELECT it.locale || \':\' || it.name FROM ingredient_translation it JOIN ingredient i ON i.id = it.ingredient_id WHERE i.restaurant_id = ? ORDER BY 1',
            [$this->restaurant->getId()],
        );

        // Edited by hand from the editor (needsReview cleared, its text the owner's).
        $edited = $this->dish('Beef burger');
        $edited->getTranslation('es')->setName('Hamburguesa de ternera');
        $edited->setNeedsReview(false);
        // Published as it was read.
        $published = $this->dish('Chicken wings');
        $published->setActive(true);
        $published->setNeedsReview(false);
        $this->em->flush();

        $token = $this->token($batch);
        $first = $this->step($batch, $token);
        $this->assertSame(['done' => false, 'remaining' => 1, 'error' => false], $first, 'the dishes, then the created category');
        $result = $this->step($batch, $token);
        $this->assertTrue($result['done'], json_encode($result));
        $this->assertFalse($result['error']);

        $this->em->clear();
        $salmon = $this->dish('Grilled salmon');
        $this->assertSame(['en' => ['Grilled salmon', 'human'], 'es' => ['[es] Grilled salmon', 'ai']], $this->texts($salmon), 'the text read stays the owner\'s, in English');
        $this->assertSame('[es] With lemon butter', $salmon->getTranslation('es')->getDescription());
        $this->assertSame('With lemon butter', $salmon->getTranslation('en')->getDescription());
        $this->assertSame(1250, $salmon->getBasePrice(), 'prices untouched');
        $this->assertTrue($salmon->isNeedsReview(), 'still for the owner to review');
        $this->assertSame(['en' => ['Caesar salad', 'human'], 'es' => ['[es] Caesar salad', 'ai']], $this->texts($this->dish('Caesar salad')), 'a dish of the import in a reused category');

        $this->assertSame(['es' => ['Hamburguesa de ternera', 'human']], $this->texts($this->dish('Hamburguesa de ternera')), 'edited by hand: untouched');
        $this->assertSame(['es' => ['Chicken wings', 'human']], $this->texts($this->dish('Chicken wings')), 'published: untouched');

        $this->assertSame(['en' => ['Mains', 'human'], 'es' => ['[es] Mains', 'ai']], $this->texts($this->category('Mains')), 'a category the import created');
        $this->assertSame(['es' => ['Starters', 'human']], $this->texts($this->category('Starters')), 'a category it reused: untouched');

        $ingredientsAfter = $this->em->getConnection()->fetchFirstColumn(
            'SELECT it.locale || \':\' || it.name FROM ingredient_translation it JOIN ingredient i ON i.id = it.ingredient_id WHERE i.restaurant_id = ? ORDER BY 1',
            [$this->restaurant->getId()],
        );
        $this->assertSame($ingredientsBefore, $ingredientsAfter, 'ingredients untouched');
        $sent = array_merge(...array_map(static fn (array $c) => $c['names'], $this->ai->calls));
        $this->assertNotContains('lemon zest', $sent);
        $this->assertNotContains('Beef burger', $sent);
        $this->assertNotContains('Chicken wings', $sent);
        $this->assertNotContains('Starters', $sent);
        $this->assertSame([['en']], array_values(array_unique(array_map(static fn (array $c) => $c['from'], $this->ai->calls), SORT_REGULAR)), 'translated from English');

        $this->assertSame(MenuImportBatch::NOTICE_TRANSLATED, $this->em->find(MenuImportBatch::class, $batch->getId())->getLanguageNotice());
        $html = $this->carta();
        $this->assertStringNotContainsString('import-lang-notice"', $html, 'gone once translated');
        $this->assertStringContainsString('Los platos y categorías importados se han traducido al español.', $html);
    }

    public function testInBatchesOf25Dishes(): void
    {
        $batch = $this->import(extraDishes: 24); // 30 dishes

        $first = $this->step($batch);
        $this->assertFalse($first['done']);
        $this->assertSame(5 + 1, $first['remaining'], '5 dishes and the created category left');
        $this->assertCount(25, $this->ai->calls[0]['names']);

        $this->assertFalse($this->step($batch)['done'], 'the last 5 dishes');
        $this->assertTrue($this->step($batch)['done'], 'then the categories');
        $this->assertCount(3, $this->ai->calls);
    }

    public function testAFailedBatchWritesNothingAndIsResumed(): void
    {
        $batch = $this->import();
        $token = $this->token($batch);

        $this->ai->fail = true;
        $result = $this->step($batch, $token);
        $this->assertFalse($result['done']);
        $this->assertTrue($result['error']);

        $this->em->clear();
        $this->assertSame(['es' => ['Grilled salmon', 'human']], $this->texts($this->dish('Grilled salmon')), 'nothing written');
        $this->assertSame(MenuImportBatch::NOTICE_TRANSLATING, $this->em->find(MenuImportBatch::class, $batch->getId())->getLanguageNotice());
        $html = $this->carta();
        $this->assertStringContainsString('Traducción a medias: quedan 7.', $html, '6 dishes and Mains');
        $this->assertStringContainsString('Continuar la traducción', $html);

        // A dish the AI leaves out is retried; the rest stays done.
        $this->ai->fail = false;
        $this->ai->skip = ['Fish and chips'];
        $result = $this->step($batch, $token);
        $this->assertFalse($result['done']);
        $this->assertFalse($result['error']);
        $this->ai->skip = [];
        $this->step($batch, $token);
        $this->assertTrue($this->step($batch, $token)['done']);

        $this->em->clear();
        $this->assertSame(['en' => ['Fish and chips', 'human'], 'es' => ['[es] Fish and chips', 'ai']], $this->texts($this->dish('Fish and chips')));
    }

    // ── Dejarla así, and when there's no notice ──

    public function testLeaveItAsItIs(): void
    {
        $batch = $this->import();
        $token = $this->token($batch);

        $this->client->request('POST', '/admin/menu/import/' . $batch->getId() . '/language/dismiss', ['_token' => $token]);
        $this->assertResponseRedirects('/admin/menu');

        $this->assertSame(MenuImportBatch::NOTICE_DISMISSED, $this->batch($batch)->getLanguageNotice());
        $this->assertSame(['es' => ['Grilled salmon', 'human']], $this->texts($this->dish('Grilled salmon')), 'nothing changed');
        $this->assertSame([], $this->ai->calls);
        $this->assertStringNotContainsString('import-lang-notice"', $this->carta());
        $this->assertTrue($this->step($batch, $token)['done'], 'translating a dismissed import does nothing');
        $this->assertSame([], $this->ai->calls);
    }

    public function testNoNoticeWhenNothingIsLeftToTranslate(): void
    {
        $batch = $this->import();
        foreach ($this->em->getRepository(Product::class)->findBy(['importBatch' => $batch]) as $product) {
            $product->setNeedsReview(false);
        }
        $this->category('Mains')->setNeedsReview(false);
        $this->em->flush();

        $this->assertStringNotContainsString('import-lang-notice"', $this->carta());
    }

    public function testBadTokenAndAnotherRestaurantsImport(): void
    {
        $batch = $this->import();
        $this->client->request('POST', '/admin/menu/import/' . $batch->getId() . '/language/translate', ['_token' => 'wrong']);
        $this->assertResponseStatusCodeSame(403);

        $other = $this->makeRestaurant();
        $otherBatch = $this->import(restaurant: $other);
        $this->client->request('POST', '/admin/menu/import/' . $otherBatch->getId() . '/language/translate', ['_token' => 'x']);
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/menu/import/' . $otherBatch->getId() . '/language/dismiss', ['_token' => 'x']);
        $this->assertResponseStatusCodeSame(403);

        $this->assertSame([], $this->ai->calls);
        $this->assertSame(MenuImportBatch::NOTICE_PENDING, $this->batch($otherBatch)->getLanguageNotice());
    }
}
