<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Product;
use App\Entity\ProductGlobalIngredient;
use App\Entity\Restaurant;
use App\Repository\GlobalIngredientRepository;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\GlobalIngredientLibraryTranslation;
use App\Service\RouterLabelTranslator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The daily library translation: groq-llama only, two passes, written as
 * source 'ai', never over an existing name, stopping at the quota and
 * resuming on the next run.
 *
 * The real provider factory runs against a MockHttpClient that answers
 * from the ids in each request (or with a scripted HTTP failure) — no
 * request can reach a real API. Every provider key is pinned in $_ENV so
 * the chain is the same on any machine. Runs are scoped to this test's own
 * ingredient codes.
 */
final class GlobalIngredientLibraryTranslationTest extends KernelTestCase
{
    private const KEY_ENVS = ['GEMINI_API_KEY', 'GROQ_API_KEY', 'OPENROUTER_API_KEY', 'OPENAI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY'];

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    /** @var list<string> hosts actually requested */
    private array $requestedHosts = [];

    private EntityManagerInterface $em;
    /** @var array<string, int> code => id */
    private array $ingredients = [];
    /** @var int[] */
    private array $restaurantIds = [];

    protected function setUp(): void
    {
        foreach (self::KEY_ENVS as $name) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
            $_ENV[$name] = '';
        }
        $_ENV['GROQ_API_KEY'] = 'test-key-never-sent';
        $_ENV['GEMINI_API_KEY'] = 'test-key-never-sent';

        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->restaurantIds as $restaurantId) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($restaurantId);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->flush();
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();
        foreach ($this->ingredients as $id) {
            $ingredient = $this->em->getRepository(GlobalIngredient::class)->find($id);
            if ($ingredient) {
                $this->em->remove($ingredient);
            }
        }
        $this->em->flush();

        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        parent::tearDown();
    }

    /** @param array<string, array{string, string}> $names locale => [name, source] */
    private function ingredient(array $names): int
    {
        $ingredient = new GlobalIngredient();
        $code = 'test-library-translation-' . uniqid();
        $ingredient->setCode($code);
        foreach ($names as $locale => [$name, $source]) {
            $t = new GlobalIngredientTranslation();
            $t->setLocale($locale);
            $t->setName($name);
            $t->setSource($source);
            $ingredient->addTranslation($t);
        }
        $this->em->persist($ingredient);
        $this->em->flush();

        return $this->ingredients[$code] = $ingredient->getId();
    }

    /**
     * @param list<int|string> $script per request, in order: an HTTP status to
     *                                 fail with, 'answer' (every id → "<en> [<locale>]",
     *                                 corrected to "... ✓" by the check pass) or
     *                                 'answer-all-but-first' (the check pass drops one id)
     */
    private function service(array $script): GlobalIngredientLibraryTranslation
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$script): MockResponse {
            $this->requestedHosts[] = parse_url($url, PHP_URL_HOST);
            $step = array_shift($script);
            if ($step === null) {
                return new MockResponse('{"error":{"message":"unexpected extra request"}}', ['http_code' => 500]);
            }
            if (is_int($step)) {
                return new MockResponse('{"error":{"message":"scripted failure"}}', ['http_code' => $step]);
            }

            $body = json_decode($options['body'], true);
            preg_match('/"([a-z]{2})"/', $body['messages'][0]['content'], $locale);
            $items = json_decode(substr($body['messages'][1]['content'], strlen('Ingredients: ')), true);
            $answer = [];
            foreach ($items as $i => $item) {
                if (isset($item['translated_name'])) {
                    if ($step === 'answer-all-but-first' && $i === 0) {
                        continue;
                    }
                    $answer[$item['id']] = $item['translated_name'] . ' ✓';
                } else {
                    $answer[$item['id']] = $item['name'] . ' [' . $locale[1] . ']';
                }
            }

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => json_encode($answer, JSON_UNESCAPED_UNICODE)]]]]));
        });
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));
        $labels = new RouterLabelTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger(), static function (int $seconds): void {});

        return new GlobalIngredientLibraryTranslation(
            $labels,
            static::getContainer()->get(GlobalIngredientRepository::class),
            static::getContainer()->get(Connection::class),
        );
    }

    /** @param list<string> $locales */
    private function translate(GlobalIngredientLibraryTranslation $service, array $locales, int $batchSize = 10): array
    {
        return $service->run($locales, $batchSize, codes: array_keys($this->ingredients));
    }

    /** @return array<string, array{string, string}> locale => [name, source] */
    private function names(int $id): array
    {
        $rows = static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT locale, name, source FROM global_ingredient_translation WHERE global_ingredient_id = ? ORDER BY locale',
            [$id],
        );
        $names = [];
        foreach ($rows as $row) {
            $names[$row['locale']] = [$row['name'], $row['source']];
        }

        return $names;
    }

    public function testFillsOnlyTheMissingNamesAsAiAfterTheCheckPass(): void
    {
        $d = GlobalIngredientTranslation::SOURCE_DATASET;
        $h = GlobalIngredientTranslation::SOURCE_HUMAN;
        $a = GlobalIngredientTranslation::SOURCE_AI;
        $tomato = $this->ingredient(['en' => ['Tomato', $d], 'es' => ['Tomate', $d], 'fr' => ['Tomate (fait main)', $h]]);
        $basil = $this->ingredient(['en' => ['Basil', $d]]);

        $result = $this->translate($this->service(['answer', 'answer', 'answer', 'answer']), ['fr', 'es']);

        self::assertSame(['written' => 2, 'failed' => 0, 'stopped' => null], array_intersect_key($result, array_flip(['written', 'failed', 'stopped'])));
        self::assertSame(['en' => ['Tomato', $d], 'es' => ['Tomate', $d], 'fr' => ['Tomate (fait main)', $h]], $this->names($tomato), 'dataset and hand-written names are never asked for nor touched');
        self::assertSame(['en' => ['Basil', $d], 'es' => ['Basil [es] ✓', $a], 'fr' => ['Basil [fr] ✓', $a]], $this->names($basil), 'both passes ran: the check pass has the last word');
        self::assertSame(['api.groq.com'], array_values(array_unique($this->requestedHosts)));
    }

    public function testASecondRunHasNothingToAsk(): void
    {
        $this->ingredient(['en' => ['Basil', GlobalIngredientTranslation::SOURCE_DATASET]]);
        $this->translate($this->service(['answer', 'answer']), ['fr']);
        $this->requestedHosts = [];

        $result = $this->translate($this->service([]), ['fr']);

        self::assertSame(0, $result['written']);
        self::assertSame([], $this->requestedHosts);
    }

    public function testStopsAtTheQuotaWithoutFallingBackAndResumesNextRun(): void
    {
        $d = GlobalIngredientTranslation::SOURCE_DATASET;
        $basil = $this->ingredient(['en' => ['Basil', $d]]);
        $mint = $this->ingredient(['en' => ['Mint', $d]]);

        // Batch 1 (Basil) goes through; batch 2 (Mint) meets a 429 that
        // outlasts every retry — the daily quota.
        $result = $this->translate($this->service(['answer', 'answer', 429, 429, 429, 429]), ['fr'], batchSize: 1);

        self::assertSame('quota', $result['stopped']);
        self::assertSame(1, $result['written']);
        self::assertArrayNotHasKey('fr', $this->names($mint));
        self::assertSame(['api.groq.com'], array_values(array_unique($this->requestedHosts)), 'no paid provider is ever tried, even though Gemini has a key here');

        $result = $this->translate($this->service(['answer', 'answer']), ['fr'], batchSize: 1);

        self::assertSame(['written' => 1, 'stopped' => null], ['written' => $result['written'], 'stopped' => $result['stopped']]);
        self::assertSame(['Basil [fr] ✓', GlobalIngredientTranslation::SOURCE_AI], $this->names($basil)['fr']);
        self::assertSame(['Mint [fr] ✓', GlobalIngredientTranslation::SOURCE_AI], $this->names($mint)['fr']);
    }

    public function testAPerMinuteLimitIsWaitedOut(): void
    {
        $basil = $this->ingredient(['en' => ['Basil', GlobalIngredientTranslation::SOURCE_DATASET]]);

        $result = $this->translate($this->service([429, 'answer', 429, 'answer']), ['fr']);

        self::assertSame(['written' => 1, 'stopped' => null], ['written' => $result['written'], 'stopped' => $result['stopped']]);
        self::assertSame('Basil [fr] ✓', $this->names($basil)['fr'][0]);
    }

    public function testANameTheCheckPassSkippedIsNotWritten(): void
    {
        $d = GlobalIngredientTranslation::SOURCE_DATASET;
        $basil = $this->ingredient(['en' => ['Basil', $d]]);
        $mint = $this->ingredient(['en' => ['Mint', $d]]);

        $result = $this->translate($this->service(['answer', 'answer-all-but-first']), ['fr']);

        self::assertSame(['written' => 1, 'failed' => 1], ['written' => $result['written'], 'failed' => $result['failed']]);
        self::assertArrayNotHasKey('fr', $this->names($basil), 'never written unchecked — left for the next run');
        self::assertSame('Mint [fr] ✓', $this->names($mint)['fr'][0]);
    }

    public function testADryRunWritesNothing(): void
    {
        $basil = $this->ingredient(['en' => ['Basil', GlobalIngredientTranslation::SOURCE_DATASET]]);

        $result = $this->service(['answer', 'answer'])->run(['fr'], 10, codes: array_keys($this->ingredients), dryRun: true);

        self::assertSame(1, $result['written']);
        self::assertSame([['locale' => 'fr', 'en' => 'Basil', 'name' => 'Basil [fr] ✓']], $result['samples']);
        self::assertArrayNotHasKey('fr', $this->names($basil));
    }

    /** A restaurant with one dish using each of $globalIngredientIds (none: no dish). */
    private function restaurantUsing(array $globalIngredientIds): int
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Biblioteca');
        $restaurant->setSlug('library-translation-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $this->em->persist($restaurant);
        if ($globalIngredientIds !== []) {
            $category = new Category();
            $category->setRestaurant($restaurant);
            $this->em->persist($category);
            $product = new Product();
            $product->setCategory($category);
            $product->setBasePrice(1000);
            foreach ($globalIngredientIds as $position => $id) {
                $link = new ProductGlobalIngredient();
                $link->setGlobalIngredient($this->em->getRepository(GlobalIngredient::class)->find($id));
                $link->setPosition($position);
                $product->addGlobalIngredientLink($link);
            }
            $this->em->persist($product);
        }
        $this->em->flush();

        return $this->restaurantIds[] = $restaurant->getId();
    }

    private function menuVersion(int $restaurantId): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$restaurantId]);
    }

    public function testOnlyMenusUsingANewlyNamedIngredientAreBumped(): void
    {
        $d = GlobalIngredientTranslation::SOURCE_DATASET;
        $basil = $this->ingredient(['en' => ['Basil', $d]]);
        $alreadyFrench = $this->ingredient(['en' => ['Mint', $d], 'fr' => ['Menthe', $d]]);
        $usesBasil = $this->restaurantUsing([$basil]);
        $usesMint = $this->restaurantUsing([$alreadyFrench]);
        $noDishes = $this->restaurantUsing([]);
        $before = array_map($this->menuVersion(...), [$usesBasil, $usesMint, $noDishes]);

        $this->translate($this->service(['answer', 'answer']), ['fr']);

        self::assertSame(
            [$before[0] + 1, $before[1], $before[2]],
            array_map($this->menuVersion(...), [$usesBasil, $usesMint, $noDishes]),
        );
    }

    public function testADryRunBumpsNoMenu(): void
    {
        $basil = $this->ingredient(['en' => ['Basil', GlobalIngredientTranslation::SOURCE_DATASET]]);
        $usesBasil = $this->restaurantUsing([$basil]);
        $before = $this->menuVersion($usesBasil);

        $this->service(['answer', 'answer'])->run(['fr'], 10, codes: array_keys($this->ingredients), dryRun: true);

        self::assertSame($before, $this->menuVersion($usesBasil));
    }
}
