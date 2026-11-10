<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Regression coverage for the "unchecking a tag/recommended box in the
 * product editor doesn't persist" bug (2026-09-03).
 *
 * The actual bug lived entirely in the frontend, not here:
 * _product_fields.html.twig is included TWICE per product edit (desktop
 * modal + mobile sheet — see _admin_js_utils.html.twig's activePanel()
 * doc), so every tag pill and the #p-recommended checkbox exists twice in
 * the DOM at once. saveProduct() (_product_js.html.twig) collected
 * checked/on tags via an *unscoped* document.querySelectorAll, which could
 * still match the closed panel's untouched duplicate (still checked, from
 * the initial data load) even after the open panel's own copy was
 * correctly unchecked — so a removal made in the visible UI never actually
 * made it into the JSON "tags" array sent to /admin/products/save. Fixed
 * by scoping that read to activePanel(), the same helper the price-variants
 * read two lines below it already used.
 *
 * That DOM-duplication bug isn't reachable from PHPUnit — it's pure
 * client-side state, and this repo has no JS test runner. What this test
 * pins down instead is the backend half of the contract the frontend fix
 * now depends on: MenuAdminController::saveProduct() must correctly remove
 * a tag when the "tags" array in the POST body simply omits it (exactly
 * what the fixed frontend now sends when a box is unchecked), verified by
 * re-reading the product from a fresh EntityManager rather than trusting
 * the in-memory entity. This was already correct before the frontend fix
 * (saveProduct() clears and rebuilds the whole tag list on every save —
 * see MenuAdminController.php) but had no regression coverage; this guards
 * it against a future change (e.g. swapping that clear-and-rebuild for a
 * smarter partial diff) breaking removal again.
 */
final class MenuAdminProductTagsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private Category $category;
    private Product $product;
    private ProductTag $recommendedTag;
    private ProductTag $genericTag;
    private ProductTag $containsGlutenTag;

    /** @var int[] restaurant ids, not entities — tests call $em->clear(), which detaches everything created before it */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Product Tags Test Restaurant');
        $this->restaurant->setSlug('product-tags-test-' . uniqid());
        $this->restaurant->setPrimaryColor('#000000');
        $this->restaurant->setCurrency('EUR');
        $this->restaurant->setDefaultLanguage('es');
        $this->em->persist($this->restaurant);
        $this->em->flush(); // assigns the id immediately, needed below and for tearDown
        $this->restaurantIdsToRemove[] = $this->restaurant->getId();

        $this->category = new Category();
        $this->category->setRestaurant($this->restaurant);
        $this->em->persist($this->category);

        $this->product = new Product();
        $this->product->setCategory($this->category);
        $this->product->setBasePrice(1000);
        $this->em->persist($this->product);

        // Mirrors DefaultTagSeeder's real "Chef's Recommendation" system tag
        // (code + isSystem, the two things product_save/the editor key off).
        $this->recommendedTag = new ProductTag($this->restaurant, 'recommended', true);
        $this->em->persist($this->recommendedTag);

        // A second, non-system tag — proves the fix isn't recommended-specific.
        $this->genericTag = new ProductTag($this->restaurant, 'vegetarian', false);
        $this->em->persist($this->genericTag);

        // A "contains-…" system tag: comes from the allergens, never from the
        // editor (ProductEffectiveTags), so saveProduct() ignores it.
        $this->containsGlutenTag = new ProductTag($this->restaurant, 'contains-gluten', true);
        $this->em->persist($this->containsGlutenTag);

        $this->em->flush();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('product-tags-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($this->restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
            }
            foreach ($this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]) as $tag) {
                $this->em->remove($tag);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /** @param int[] $tagIds */
    private function save(array $tagIds): void
    {
        $this->client->request(
            'POST', '/admin/products/save', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'id' => $this->product->getId(),
                'categoryId' => $this->category->getId(),
                'basePrice' => 10.0,
                'translations' => ['es' => ['name' => 'Test dish', 'description' => null]],
                'tags' => $tagIds,
            ])
        );
        self::assertResponseIsSuccessful();
    }

    /** @return string[] tag codes currently assigned, read from a fresh EntityManager — not the in-memory entity */
    private function reloadTagCodes(): array
    {
        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($this->product->getId());
        $codes = array_map(static fn (ProductTag $t) => $t->getCode(), $product->getTags()->toArray());
        sort($codes);

        return $codes;
    }

    public function testMarkingRecommendedPersists(): void
    {
        $this->save([$this->recommendedTag->getId()]);

        self::assertSame(['recommended'], $this->reloadTagCodes());
    }

    public function testUncheckingRecommendedPersistsTheRemoval(): void
    {
        // Start with the tag assigned, exactly like editing an already-recommended dish.
        $this->save([$this->recommendedTag->getId()]);
        self::assertSame(['recommended'], $this->reloadTagCodes());

        // The fixed frontend now sends "tags" without the recommended id at
        // all once the box is unchecked (see activePanel() scoping above) —
        // simulate that directly.
        $this->save([]);
        self::assertSame([], $this->reloadTagCodes(), 'Unchecking "recomendado" must remove the tag, not leave it assigned.');
    }

    public function testUncheckingAGenericTagAlsoPersistsTheRemoval(): void
    {
        // Same class of bug affected any .tag-pill, not just #p-recommended
        // — cover one generic tag too, and confirm removing one leaves an
        // unrelated tag intact rather than clearing everything.
        $this->save([$this->recommendedTag->getId(), $this->genericTag->getId()]);
        self::assertSame(['recommended', 'vegetarian'], $this->reloadTagCodes());

        $this->save([$this->recommendedTag->getId()]);
        self::assertSame(['recommended'], $this->reloadTagCodes(), 'Removing one tag while keeping another must persist correctly.');
    }

    public function testAContainsTagSentByTheEditorIsNeverSaved(): void
    {
        $this->save([$this->containsGlutenTag->getId(), $this->genericTag->getId()]);

        self::assertSame(['vegetarian'], $this->reloadTagCodes());
    }

    public function testAnAlreadySavedContainsTagIsLeftAlone(): void
    {
        // An old hand-ticked row (from before the tag became automatic).
        $this->product->addTag($this->containsGlutenTag);
        $this->em->flush();

        $this->save([$this->genericTag->getId()]);
        self::assertSame(['contains-gluten', 'vegetarian'], $this->reloadTagCodes(), 'ignored both ways: not removed either');

        $this->save([]);
        self::assertSame(['contains-gluten'], $this->reloadTagCodes(), 'the other tags still come off');
    }

    public function testTheEditorShowsTheContainsPillLocked(): void
    {
        foreach ([$this->containsGlutenTag, $this->genericTag] as $tag) {
            $t = new ProductTagTranslation();
            $t->setLocale('es');
            $t->setName($tag === $this->genericTag ? 'Vegetariano' : 'Contiene gluten');
            $tag->addTranslation($t);
            $this->em->persist($t);
        }
        $this->em->flush();
        $this->em->clear(); // the page reads $restaurant->getProductTags(), filled from the DB, not this in-memory restaurant

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();

        $locked = $crawler->filter('.tag-pill[data-tag-id="' . $this->containsGlutenTag->getId() . '"]');
        self::assertGreaterThan(0, $locked->count());
        $locked->each(function ($pill) {
            self::assertSame('gluten', $pill->attr('data-allergen-tag'));
            self::assertSame('true', $pill->attr('aria-disabled'));
            self::assertNull($pill->attr('onclick'), 'never clicked');
        });

        $normal = $crawler->filter('.tag-pill[data-tag-id="' . $this->genericTag->getId() . '"]')->first();
        self::assertNull($normal->attr('data-allergen-tag'), 'an owner tag stays a normal pill');
        self::assertSame('toggleTagPill(this)', $normal->attr('onclick'));
    }

    public function testPillNamesFollowTheAdminPanelLanguage(): void
    {
        $this->restaurant->setAdminLocale('en');
        foreach ([[$this->containsGlutenTag, 'Contiene gluten', 'Contains Gluten'], [$this->genericTag, 'Vegetariano', null]] as [$tag, $es, $en]) {
            foreach (array_filter(['es' => $es, 'en' => $en]) as $locale => $name) {
                $t = new ProductTagTranslation();
                $t->setLocale($locale);
                $t->setName($name);
                $tag->addTranslation($t);
                $this->em->persist($t);
            }
        }
        $this->em->flush();
        $this->em->clear();

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();

        $name = fn (ProductTag $tag) => trim($crawler->filter('.tag-pill[data-tag-id="' . $tag->getId() . '"]')->first()->text());
        self::assertStringEndsWith('Contains Gluten', $name($this->containsGlutenTag), 'English panel: the English name');
        self::assertStringEndsWith('Vegetariano', $name($this->genericTag), 'no English name: the menu\'s own one, never hidden');
    }
}
