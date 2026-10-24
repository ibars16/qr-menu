<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\ProductTranslatorInterface;
use App\Service\TagTranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tag translations: renaming a tag drops its generated ('ai'/'preset')
 * translations and keeps the owner's ('human'); missing translations are
 * filled behind the loading screen (warm-translations), never by the menu
 * render and never through the queue. Both AI translators are fakes — and
 * every dish/category has its 'de' translation, so no real call can happen.
 */
final class TagTranslationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?int $restaurantId = null;
    private string $slug;
    private int $tagId;

    private object $fakeTagAi;
    private object $fakeDishAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // Keep the fake translators across requests (a reboot would rebuild the container).
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->fakeTagAi = new class implements TagTranslatorInterface {
            /** @var list<array{names: list<string>, locale: string}> */
            public array $calls = [];

            public function translate(array $names, string $targetLocale): array
            {
                $this->calls[] = ['names' => array_values($names), 'locale' => $targetLocale];
                $out = [];
                foreach ($names as $id => $name) {
                    $out[$id] = "[{$targetLocale}] {$name}";
                }

                return $out;
            }
        };
        $this->fakeDishAi = new class implements ProductTranslatorInterface {
            public int $calls = 0;

            public function translate(array $products, string $targetLocale): array
            {
                ++$this->calls;

                return [];
            }
        };
        static::getContainer()->set(TagTranslatorInterface::class, $this->fakeTagAi);
        static::getContainer()->set(ProductTranslatorInterface::class, $this->fakeDishAi);
    }

    protected function tearDown(): void
    {
        $restaurant = $this->restaurantId ? $this->em->getRepository(Restaurant::class)->find($this->restaurantId) : null;
        if ($restaurant) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
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

    /** @param array<string, array{0: string, 1: string}> $tagTranslations locale => [name, source] */
    private function makeRestaurant(array $tagTranslations): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Tags');
        $this->slug = 'tag-translation-test-' . uniqid();
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme('maison');
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);

        $tag = new ProductTag($restaurant, 'casero');
        foreach ($tagTranslations as $locale => [$name, $source]) {
            $t = new ProductTagTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $tag->addTranslation($t);
            $this->em->persist($t);
        }
        $this->em->persist($tag);

        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $this->em->persist($category);
        foreach (['es' => 'Entrantes', 'de' => 'Vorspeisen'] as $locale => $name) {
            $ct = new CategoryTranslation();
            $ct->setLocale($locale);
            $ct->setName($name);
            $category->addTranslation($ct);
            $this->em->persist($ct);
        }

        $product = new Product();
        $product->setActive(true);
        $product->setBasePrice(1250);
        $category->addProduct($product);
        $product->addTag($tag);
        $this->em->persist($product);
        foreach (['es' => 'Croquetas', 'de' => 'Kroketten'] as $locale => $name) {
            $pt = new ProductTranslation();
            $pt->setLocale($locale);
            $pt->setName($name);
            $product->addTranslation($pt);
            $this->em->persist($pt);
        }

        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $this->tagId = $tag->getId();
        // The requests below share this EntityManager (disableReboot): start
        // them from the DB, not from these in-memory objects whose inverse
        // collections (Restaurant::$productTags…) were never filled.
        $this->em->clear();

        return $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
    }

    private function loginOwner(Restaurant $restaurant): void
    {
        $user = new User();
        $user->setEmail('tag-translation-' . uniqid() . '@example.test');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    private function editTag(string $name): void
    {
        $this->client->request('POST', '/admin/tags/' . $this->tagId . '/edit', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => $name]));
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, array{0: string, 1: string}> locale => [name, source], fresh from the DB */
    private function tagTranslations(): array
    {
        $this->em->clear();
        $out = [];
        foreach ($this->em->getRepository(ProductTagTranslation::class)->findBy(['tag' => $this->tagId]) as $t) {
            $out[$t->getLocale()] = [$t->getName(), $t->getSource()];
        }
        ksort($out);

        return $out;
    }

    private function menuVersion(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$this->restaurantId]);
    }

    private function queued(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }

    public function testRenamingDropsAiAndPresetTranslationsAndKeepsHumanOnes(): void
    {
        $restaurant = $this->makeRestaurant([
            'es' => ['Casero', ProductTagTranslation::SOURCE_HUMAN],
            'en' => ['Homemade', ProductTagTranslation::SOURCE_AI],
            'fr' => ['Fait maison', ProductTagTranslation::SOURCE_PRESET],
            'de' => ['Hausgemacht', ProductTagTranslation::SOURCE_HUMAN],
        ]);
        $this->loginOwner($restaurant);
        $version = $this->menuVersion();

        $this->editTag('De la casa');

        self::assertSame([
            'de' => ['Hausgemacht', ProductTagTranslation::SOURCE_HUMAN],
            'es' => ['De la casa', ProductTagTranslation::SOURCE_HUMAN],
        ], $this->tagTranslations(), "'ai' and 'preset' gone, 'human' kept, the renamed one is the owner's");
        self::assertGreaterThan($version, $this->menuVersion());
    }

    public function testRenamingAPresetDefaultLanguageNameMakesItHumanInsteadOfDroppingIt(): void
    {
        $restaurant = $this->makeRestaurant([
            'es' => ['Vegano', ProductTagTranslation::SOURCE_PRESET],
            'en' => ['Vegan', ProductTagTranslation::SOURCE_PRESET],
        ]);
        $this->loginOwner($restaurant);

        $this->editTag('100% vegetal');

        self::assertSame(['es' => ['100% vegetal', ProductTagTranslation::SOURCE_HUMAN]], $this->tagTranslations());
    }

    public function testSavingTheSameNameDropsNothing(): void
    {
        $restaurant = $this->makeRestaurant([
            'es' => ['Casero', ProductTagTranslation::SOURCE_HUMAN],
            'en' => ['Homemade', ProductTagTranslation::SOURCE_AI],
            'fr' => ['Fait maison', ProductTagTranslation::SOURCE_PRESET],
        ]);
        $this->loginOwner($restaurant);
        $before = $this->tagTranslations();

        $this->editTag('Casero');

        self::assertSame($before, $this->tagTranslations());
    }

    public function testWarmTranslationsTranslatesMissingTagsAndBumpsTheMenuVersion(): void
    {
        $this->makeRestaurant(['es' => ['Casero', ProductTagTranslation::SOURCE_HUMAN]]);
        $version = $this->menuVersion();
        $queued = $this->queued();

        $this->client->request('POST', '/r/' . $this->slug . '/warm-translations?lang=de');
        self::assertResponseIsSuccessful();

        self::assertSame([['names' => ['Casero'], 'locale' => 'de']], $this->fakeTagAi->calls, 'one AI call for the locale');
        self::assertSame([
            'de' => ['[de] Casero', ProductTagTranslation::SOURCE_AI],
            'es' => ['Casero', ProductTagTranslation::SOURCE_HUMAN],
        ], $this->tagTranslations());
        self::assertGreaterThan($version, $this->menuVersion(), 'tag names are cached per menu version');
        self::assertSame($queued, $this->queued(), 'no queue');
        self::assertSame(0, $this->fakeDishAi->calls, 'dishes were already translated');

        $html = $this->client->request('GET', '/r/' . $this->slug . '?lang=de&currency=EUR&mtw=1')->html();
        self::assertStringContainsString('[de] Casero', $html);
    }

    public function testTheMenuRenderNeverQueuesNorCallsTheAiForTags(): void
    {
        $this->makeRestaurant(['es' => ['Casero', ProductTagTranslation::SOURCE_HUMAN]]);
        $queued = $this->queued();

        $this->client->request('GET', '/r/' . $this->slug . '?lang=de&currency=EUR&mtw=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Casero', $this->client->getResponse()->getContent(), 'default-language name meanwhile');

        $this->client->request('GET', '/r/' . $this->slug . '?lang=de&currency=EUR');
        self::assertStringContainsString('warm-translations', $this->client->getResponse()->getContent(), 'a missing tag sends a first visit through the loading screen');

        self::assertSame([], $this->fakeTagAi->calls);
        self::assertSame(0, $this->fakeDishAi->calls);
        self::assertSame($queued, $this->queued(), 'nothing queued');
        self::assertSame(['es' => ['Casero', ProductTagTranslation::SOURCE_HUMAN]], $this->tagTranslations());
    }
}
