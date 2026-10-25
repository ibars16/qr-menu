<?php

namespace App\Tests\Service\AllergenBackfill;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\ClassificationLog;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientAllergen;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientAllergen;
use App\Entity\IngredientTranslation;
use App\Entity\Product;
use App\Entity\ProductGlobalIngredient;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Enum\ClassificationStatus;
use App\Repository\AllergenRepository;
use App\Service\AI\AIProviderFactory;
use App\Service\AllergenBackfill\AllergenDecision;
use App\Service\AllergenBackfill\AllergenKeywordRules;
use App\Service\AllergenBackfill\AllergenReviewQueue;
use App\Service\AllergenBackfill\GroqAllergenClassifier;
use App\Service\AllergenBackfill\IngredientAllergenBackfill;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The real backfill service against qrmenu_test, with the Groq API played
 * by a MockHttpClient that answers by ingredient name — no request can
 * reach a real API. Checks the write rules: clear answers are written as
 * they are and doubtful ones as the union of every opinion (source/
 * reviewed_by 'ai', the union recorded in classification_log), what nobody
 * named anything for stays pending there and is not sent again, YAML/
 * hand-set ingredients are never touched, private ingredients are sent in
 * their own language, and the menus affected get their version bumped.
 */
final class IngredientAllergenBackfillTest extends KernelTestCase
{
    private const KEY_ENVS = ['GEMINI_API_KEY', 'GROQ_API_KEY', 'OPENROUTER_API_KEY', 'OPENAI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY'];

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    private ?EntityManagerInterface $em = null;
    private ?int $restaurantId = null;

    /** @var list<int> */
    private array $globalIds = [];

    /** @var list<int> dishes linking a global ingredient — removed before the globals */
    private array $productIds = [];

    /** @var list<array{system: string, user: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        foreach (self::KEY_ENVS as $name) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
            $_ENV[$name] = '';
        }
        $_ENV['GROQ_API_KEY'] = 'test-key-never-sent';
    }

    protected function tearDown(): void
    {
        if ($this->em !== null) {
            $this->em->clear();
            foreach ($this->productIds as $id) {
                if ($product = $this->em->find(Product::class, $id)) {
                    $this->em->remove($product);
                }
            }
            $this->em->flush();
            $privateIds = $this->restaurantId === null ? [] : array_map(
                static fn (Ingredient $i) => $i->getId(),
                $this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $this->restaurantId]),
            );
            foreach ([['global_ingredient', $this->globalIds], ['ingredient', $privateIds]] as [$type, $ids]) {
                if ($ids !== []) {
                    $this->em->createQuery('DELETE FROM ' . ClassificationLog::class . ' l WHERE l.classificationType = :t AND l.subjectType = :s AND l.subjectId IN (:ids)')
                        ->execute(['t' => AllergenReviewQueue::CLASSIFICATION_TYPE, 's' => $type, 'ids' => $ids]);
                }
            }
            foreach ($this->globalIds as $id) {
                if ($ingredient = $this->em->find(GlobalIngredient::class, $id)) {
                    $this->em->remove($ingredient);
                }
            }
            if ($this->restaurantId !== null && ($restaurant = $this->em->find(Restaurant::class, $this->restaurantId))) {
                foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
                    $this->em->remove($ingredient);
                }
                $this->em->flush();
                $this->em->remove($restaurant);
            }
            $this->em->flush();
        }

        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        parent::tearDown();
    }

    public function testDoubtfulAnswersAreAppliedAsTheUnionAndProtectedOnesNeverAre(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tag = uniqid();

        $egg = $this->global("Backfill egg {$tag}");
        $water = $this->global("Backfill water {$tag}");
        $wine = $this->global("Backfill wine {$tag}");
        $split = $this->global("Backfill mystery sauce {$tag}");
        $extract = $this->global("Backfill yeast extract {$tag}");
        $soySauce = $this->global("Backfill soy sauce {$tag}", ['soybeans' => GlobalIngredientAllergen::SOURCE_YAML]);

        $restaurant = new Restaurant();
        $restaurant->setName('Casa Alérgenos');
        $restaurant->setSlug('allergen-backfill-test-' . $tag);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $almond = $this->private($restaurant, ['ca' => "ametllat {$tag}"]);
        $handSet = $this->private($restaurant, ['es' => "huevo poche {$tag}"], 'gluten');
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $versionBefore = $restaurant->getMenuContentVersion();

        // Name fragment => [first pass, review pass]
        $answers = [
            'Backfill egg' => [['eggs' => 'contains'], ['eggs' => 'contains']],
            'Backfill water' => [[], []],
            'Backfill wine' => [['sulphites' => 'may_contain'], ['sulphites' => 'may_contain']],
            'Backfill mystery sauce' => [['milk' => 'may_contain'], ['milk' => 'contains', 'eggs' => 'contains']],
            'Backfill yeast extract' => [[], []],
            'Backfill soy sauce' => [['soybeans' => 'contains', 'gluten' => 'contains'], ['soybeans' => 'contains', 'gluten' => 'contains']],
            'ametllat' => [['nuts' => 'contains'], ['nuts' => 'contains']],
            'huevo poche' => [['eggs' => 'contains'], ['eggs' => 'contains']],
        ];

        $backfill = $this->backfill($answers);
        $subjects = array_values(array_filter(
            $backfill->findSubjects(
                array_map(fn (int $id) => $this->em->find(GlobalIngredient::class, $id)->getCode(), $this->globalIds),
                [$this->restaurantId],
                true,
            ),
            static fn (array $s) => str_contains($s['name'], $tag),
        ));
        self::assertCount(8, $subjects);

        $outcomes = [];
        foreach ($backfill->run($subjects, 20, false) as $result) {
            $outcomes[explode(' ' . $tag, $result['subject']['name'])[0]] = $result['decision']->outcome;
        }

        self::assertSame(AllergenDecision::APPLIED, $outcomes['Backfill egg']);
        self::assertSame(AllergenDecision::NO_ALLERGENS, $outcomes['Backfill water']);
        self::assertSame(AllergenDecision::APPLIED_UNION, $outcomes['Backfill wine'], '"may contain"');
        self::assertSame(AllergenDecision::APPLIED_UNION, $outcomes['Backfill mystery sauce'], 'passes disagree');
        self::assertSame(AllergenDecision::REVIEW, $outcomes['Backfill yeast extract'], 'processed, nobody named anything');
        self::assertSame(AllergenDecision::SUGGESTION, $outcomes['Backfill soy sauce']);
        self::assertSame(AllergenDecision::APPLIED, $outcomes['ametllat']);
        self::assertSame(AllergenDecision::SUGGESTION, $outcomes['huevo poche']);

        // Exactly two AI calls: one classify, one review, for all eight.
        self::assertCount(2, $this->requests);
        self::assertStringContainsString('"name":"ametllat ' . $tag . '","lang":"ca"', $this->requests[0]['user']);
        self::assertStringContainsString('reviewer', $this->requests[1]['system']);

        $this->em->clear();

        $egg = $this->em->find(GlobalIngredient::class, $egg);
        self::assertSame('ai', $egg->getAllergensReviewedBy());
        self::assertSame(['eggs' => 'contains'], $this->links($egg));
        self::assertSame('ai', $egg->getAllergenLinks()->first()->getSource());

        $water = $this->em->find(GlobalIngredient::class, $water);
        self::assertSame('ai', $water->getAllergensReviewedBy());
        self::assertSame([], $this->links($water));

        // Doubtful: the union, "contains" winning, rules' expectation included (wine → sulphites).
        $wineEntity = $this->em->find(GlobalIngredient::class, $wine);
        self::assertSame('ai', $wineEntity->getAllergensReviewedBy());
        self::assertSame(['sulphites' => 'contains'], $this->links($wineEntity));
        self::assertSame(['eggs' => 'contains', 'milk' => 'contains'], $this->links($this->em->find(GlobalIngredient::class, $split)));

        $wineLog = $this->unionLog('global_ingredient', $wine);
        self::assertNull($wineLog->getLabel());
        self::assertSame(['sulphites' => 'contains'], $wineLog->getAttributes()['applied']);
        self::assertSame(['sulphites' => 'may_contain'], $wineLog->getAttributes()['proposal']);
        self::assertContains('"puede contener"', $wineLog->getAttributes()['reasons']);
        self::assertSame(['milk' => 'may_contain'], $this->unionLog('global_ingredient', $split)->getAttributes()['first_pass']);

        // Empty union: nothing written, pending.
        $extractEntity = $this->em->find(GlobalIngredient::class, $extract);
        self::assertNull($extractEntity->getAllergensReviewedAt());
        self::assertSame([], $this->links($extractEntity));
        self::assertSame('review', $this->pendingLog('global_ingredient', $extract)->getAttributes()['outcome']);

        $soySauce = $this->em->find(GlobalIngredient::class, $soySauce);
        self::assertSame(['soybeans' => 'contains'], $this->links($soySauce), 'YAML ingredient untouched');
        self::assertSame('yaml', $soySauce->getAllergenLinks()->first()->getSource());
        self::assertSame('suggestion', $this->pendingLog('global_ingredient', $soySauce->getId())->getAttributes()['outcome']);

        $almond = $this->em->find(Ingredient::class, $almond);
        self::assertSame('ai', $almond->getAllergensReviewedBy());
        self::assertSame(['nuts' => 'contains'], $this->links($almond));
        self::assertGreaterThan($versionBefore, $this->em->find(Restaurant::class, $this->restaurantId)->getMenuContentVersion());

        $handSet = $this->em->find(Ingredient::class, $handSet);
        self::assertNull($handSet->getAllergensReviewedAt(), 'hand-set ingredient untouched');
        self::assertSame(['gluten' => 'contains'], $this->links($handSet));
        self::assertSame('suggestion', $this->pendingLog('ingredient', $handSet->getId())->getAttributes()['outcome']);
        self::assertNull($this->pendingLog('global_ingredient', $egg->getId()), 'applied ones are not queued');

        // A second run skips everything reviewed, protected or pending: no AI call at all.
        $codes = array_map(fn (int $id) => $this->em->find(GlobalIngredient::class, $id)->getCode(), $this->globalIds);
        $again = array_filter($backfill->findSubjects($codes, [$this->restaurantId], false), static fn (array $s) => str_contains($s['name'], $tag));
        self::assertSame([], $again);
        $requestsBefore = count($this->requests);
        self::assertSame([], $backfill->run(array_values($again), 20, false));
        self::assertCount($requestsBefore, $this->requests);

        // --include-pending sends the pending one again; once something is named, it's applied as the union.
        $pending = array_values(array_filter($backfill->findSubjects($codes, [$this->restaurantId], false, null, null, true), static fn (array $s) => str_contains($s['name'], $tag)));
        self::assertSame(["Backfill yeast extract {$tag}"], array_column($pending, 'name'));
        $this->backfill(['Backfill yeast extract' => [['gluten' => 'may_contain'], []]])->run($pending, 20, false);
        $this->em->clear();
        self::assertNull($this->pendingLog('global_ingredient', $extract));
        self::assertSame(['gluten' => 'may_contain'], $this->unionLog('global_ingredient', $extract)->getAttributes()['applied']);
        self::assertSame(['gluten' => 'may_contain'], $this->links($this->em->find(GlobalIngredient::class, $extract)));
    }

    public function testPendingDoubtfulOnesAreAppliedAsTheUnionAndMenusUsingThemAreBumped(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tag = uniqid();

        $sauce = $this->global("Backfill mystery sauce {$tag}");
        $butter = $this->global("Backfill butter blend {$tag}");
        $extract = $this->global("Backfill yeast extract {$tag}");
        $yaml = $this->global("Backfill soy sauce {$tag}", ['soybeans' => GlobalIngredientAllergen::SOURCE_YAML]);

        // A dish in another restaurant uses the sauce: its menu must be bumped.
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Unión');
        $restaurant->setSlug('allergen-union-test-' . $tag);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $category = new Category();
        $category->setRestaurant($restaurant);
        $this->em->persist($category);
        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice(1000);
        $this->em->persist($product);
        $link = new ProductGlobalIngredient();
        $link->setProduct($product);
        $link->setGlobalIngredient($this->em->find(GlobalIngredient::class, $sauce));
        $product->addGlobalIngredientLink($link);
        $this->em->persist($link);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $this->productIds[] = $product->getId();
        $versionBefore = $restaurant->getMenuContentVersion();

        $this->pendingRow($sauce, ['milk' => 'may_contain'], ['eggs' => 'contains', 'milk' => 'contains']);
        $this->pendingRow($butter, null, []);
        $this->pendingRow($extract, [], []);
        $this->pendingRow($yaml, ['gluten' => 'contains'], ['gluten' => 'contains']);
        $this->em->flush();

        $outcomes = [];
        foreach ($this->backfill([])->applyPendingUnion(false) as $result) {
            if (str_contains($result['subject']['name'], $tag)) {
                $outcomes[explode(' ' . $tag, $result['subject']['name'])[0]] = [$result['decision']->outcome, $result['decision']->allergens];
            }
        }

        self::assertSame([
            'Backfill mystery sauce' => [AllergenDecision::APPLIED_UNION, ['eggs' => 'contains', 'milk' => 'contains']],
            'Backfill butter blend' => [AllergenDecision::APPLIED_UNION, ['milk' => 'contains']],
            'Backfill yeast extract' => [AllergenDecision::REVIEW, []],
        ], $outcomes, 'the YAML one is skipped');
        self::assertCount(0, $this->requests, 'no AI call');

        $this->em->clear();
        foreach ([$sauce => ['eggs' => 'contains', 'milk' => 'contains'], $butter => ['milk' => 'contains']] as $id => $expected) {
            $ingredient = $this->em->find(GlobalIngredient::class, $id);
            self::assertSame('ai', $ingredient->getAllergensReviewedBy());
            self::assertSame($expected, $this->links($ingredient));
            self::assertSame($expected, $this->unionLog('global_ingredient', $id)->getAttributes()['applied']);
            self::assertNull($this->pendingLog('global_ingredient', $id));
        }
        self::assertNull($this->em->find(GlobalIngredient::class, $extract)->getAllergensReviewedAt());
        self::assertNotNull($this->pendingLog('global_ingredient', $extract), 'empty union stays pending');
        self::assertSame(['soybeans' => 'contains'], $this->links($this->em->find(GlobalIngredient::class, $yaml)), 'YAML untouched');
        self::assertNotNull($this->pendingLog('global_ingredient', $yaml));
        self::assertGreaterThan($versionBefore, $this->em->find(Restaurant::class, $this->restaurantId)->getMenuContentVersion());
    }

    public function testImportQueuesOldReviewItemsAndReappliesWithdrawnOnesTheRulesNowAllow(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tag = uniqid();

        $juice = $this->global("Orange juice from concentrate {$tag}");
        $fig = $this->global("Dried fig {$tag}");
        $sauce = $this->global("Backfill mystery sauce {$tag}");
        $reviewedSince = $this->global("Backfill egg {$tag}", ['eggs' => GlobalIngredientAllergen::SOURCE_AI]);

        $row = static fn (int $id, string $result, string $first, string $second, string $why) => [
            'tipo' => 'global', 'id' => (string) $id, 'resultado' => $result,
            'propuesta_1a_pasada' => $first, 'propuesta_revision' => $second, 'motivo' => $why,
        ];
        $counts = $this->backfill([])->importReviewList([
            $row($juice, 'review', 'ninguno', 'ninguno', 'DESAPLICADO — se había aplicado y con las reglas actuales va a revisión · producto procesado («concentrate»)'),
            $row($fig, 'review', 'ninguno', 'ninguno', 'DESAPLICADO — se había aplicado y con las reglas actuales va a revisión · producto procesado («dried»)'),
            $row($sauce, 'review', 'milk', 'eggs, milk', 'la 1ª pasada y la revisión no coinciden · compuesto'),
            $row($reviewedSince, 'review', 'eggs', 'eggs', 'x'),
        ], false);

        self::assertSame(['queued' => 2, 'reapplied' => 1, 'skipped' => 1], $counts);
        self::assertCount(0, $this->requests, 'no AI call');

        $this->em->clear();
        self::assertSame('ai', $this->em->find(GlobalIngredient::class, $juice)->getAllergensReviewedBy(), 'juice re-applied as "no allergens"');
        self::assertNull($this->pendingLog('global_ingredient', $juice));
        self::assertNull($this->em->find(GlobalIngredient::class, $fig)->getAllergensReviewedBy());
        self::assertStringContainsString('dried', implode(' ', $this->pendingLog('global_ingredient', $fig)->getAttributes()['reasons']));
        $sauceLog = $this->pendingLog('global_ingredient', $sauce);
        self::assertSame(['eggs' => 'contains', 'milk' => 'contains'], $sauceLog->getAttributes()['proposal']);
        self::assertSame(['milk' => 'contains'], $sauceLog->getAttributes()['first_pass']);
        self::assertNull($this->pendingLog('global_ingredient', $reviewedSince));

        // Importing again changes nothing.
        self::assertSame(['queued' => 0, 'reapplied' => 0, 'skipped' => 4], $this->backfill([])->importReviewList([
            $row($juice, 'review', 'ninguno', 'ninguno', 'DESAPLICADO'), $row($fig, 'review', 'ninguno', 'ninguno', 'DESAPLICADO'),
            $row($sauce, 'review', 'milk', 'eggs, milk', 'x'), $row($reviewedSince, 'review', 'eggs', 'eggs', 'x'),
        ], false));
    }

    /**
     * @param array<string, string>|null $first
     * @param array<string, string>      $proposal
     */
    private function pendingRow(int $globalId, ?array $first, array $proposal): void
    {
        $log = new ClassificationLog();
        $log->setSubjectType('global_ingredient');
        $log->setSubjectId($globalId);
        $log->setClassificationType(AllergenReviewQueue::CLASSIFICATION_TYPE);
        $log->setStatus(ClassificationStatus::PENDING_REVIEW);
        $log->setAttributes(['proposal' => (object) $proposal, 'first_pass' => $first !== null ? (object) $first : null, 'reasons' => ['test'], 'outcome' => 'review']);
        $this->em->persist($log);
    }

    private function unionLog(string $subjectType, int $id): ?ClassificationLog
    {
        return $this->em->getRepository(ClassificationLog::class)->findOneBy([
            'subjectType' => $subjectType,
            'subjectId' => $id,
            'classificationType' => AllergenReviewQueue::CLASSIFICATION_TYPE,
            'status' => ClassificationStatus::AUTO_APPLIED_UNION,
        ]);
    }

    /** @return array<string, string> code => presence, sorted */
    private function links(GlobalIngredient|Ingredient $ingredient): array
    {
        $links = [];
        foreach ($ingredient->getAllergenLinks() as $link) {
            $links[$link->getAllergen()->getCode()] = $link->getPresence()->value;
        }
        ksort($links);

        return $links;
    }

    private function pendingLog(string $subjectType, int $id): ?ClassificationLog
    {
        return $this->em->getRepository(ClassificationLog::class)->findOneBy([
            'subjectType' => $subjectType,
            'subjectId' => $id,
            'classificationType' => AllergenReviewQueue::CLASSIFICATION_TYPE,
            'status' => ClassificationStatus::PENDING_REVIEW,
        ]);
    }

    public function testRecheckWithdrawsEmptyDoubtfulOnesAndCompletesTheRest(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tag = uniqid();

        $egg = $this->global("Backfill egg {$tag}", ['eggs' => GlobalIngredientAllergen::SOURCE_AI]);
        $crust = $this->global("Backfill crust {$tag}", ['gluten' => GlobalIngredientAllergen::SOURCE_AI]);
        $extract = $this->global("Backfill yeast extract {$tag}", [], GlobalIngredientAllergen::SOURCE_AI);
        $apple = $this->global("Backfill apple {$tag}", [], GlobalIngredientAllergen::SOURCE_AI);
        $yamlSauce = $this->global("Backfill soy sauce {$tag}", ['soybeans' => GlobalIngredientAllergen::SOURCE_YAML]);

        $restaurant = new Restaurant();
        $restaurant->setName('Casa Recheck');
        $restaurant->setSlug('allergen-recheck-test-' . $tag);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $sauce = $this->private($restaurant, ['es' => "salsa de queso {$tag}"]);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $sauceEntity = $this->em->find(Ingredient::class, $sauce);
        $link = new IngredientAllergen();
        $link->setAllergen($this->allergen('gluten'));
        $link->setPresence(AllergenPresence::CONTAINS);
        $link->setSource(IngredientAllergen::SOURCE_AI);
        $sauceEntity->addAllergenLink($link);
        $this->em->persist($link);
        $sauceEntity->markAllergensReviewed(IngredientAllergen::SOURCE_AI);
        $this->em->flush();
        $versionBefore = $restaurant->getMenuContentVersion();

        $codes = array_map(fn (int $id) => $this->em->find(GlobalIngredient::class, $id)->getCode(), $this->globalIds);
        $outcomes = [];
        foreach ($this->backfill([])->recheckApplied(false, $codes, [$this->restaurantId]) as $result) {
            $outcomes[explode(' ' . $tag, $result['subject']['name'])[0]] = $result['decision']->outcome;
        }

        self::assertSame([
            'Backfill egg' => AllergenDecision::APPLIED,
            'Backfill crust' => AllergenDecision::APPLIED_UNION,
            'Backfill yeast extract' => AllergenDecision::REVIEW,
            'Backfill apple' => AllergenDecision::NO_ALLERGENS,
            'salsa de queso' => AllergenDecision::APPLIED_UNION,
        ], $outcomes, 'the YAML one is not even looked at');
        self::assertCount(0, $this->requests, 'no AI call');

        $this->em->clear();
        // Composite with its allergens: kept as is, nothing to record.
        self::assertSame(['gluten' => 'contains'], $this->links($this->em->find(GlobalIngredient::class, $crust)));
        self::assertNull($this->unionLog('global_ingredient', $crust));

        // Processed, no allergens: withdrawn, back to never reviewed, pending.
        $ingredient = $this->em->find(GlobalIngredient::class, $extract);
        self::assertNull($ingredient->getAllergensReviewedBy(), 'withdrawn: back to never reviewed');
        self::assertNull($ingredient->getAllergensReviewedAt());
        self::assertSame([], $this->links($ingredient));
        self::assertStringStartsWith('DESAPLICADO', $this->pendingLog('global_ingredient', $extract)->getAttributes()['reasons'][0]);

        self::assertSame('ai', $this->em->find(GlobalIngredient::class, $egg)->getAllergensReviewedBy());
        self::assertSame(['eggs' => 'contains'], $this->links($this->em->find(GlobalIngredient::class, $egg)));
        self::assertSame('ai', $this->em->find(GlobalIngredient::class, $apple)->getAllergensReviewedBy());
        self::assertSame('yaml', $this->em->find(GlobalIngredient::class, $yamlSauce)->getAllergensReviewedBy());

        // "queso": the rules expect milk — added to what it had, recorded, menu bumped.
        $sauceEntity = $this->em->find(Ingredient::class, $sauce);
        self::assertSame('ai', $sauceEntity->getAllergensReviewedBy());
        self::assertSame(['gluten' => 'contains', 'milk' => 'contains'], $this->links($sauceEntity));
        self::assertSame(['gluten' => 'contains', 'milk' => 'contains'], $this->unionLog('ingredient', $sauce)->getAttributes()['applied']);
        self::assertGreaterThan($versionBefore, $this->em->find(Restaurant::class, $this->restaurantId)->getMenuContentVersion());
    }

    /** @param array<string, array{array<string, string>, array<string, string>}> $answers */
    private function backfill(array $answers): IngredientAllergenBackfill
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answers): MockResponse {
            $body = json_decode($options['body'], true);
            $system = $body['messages'][0]['content'];
            $user = $body['messages'][1]['content'];
            $this->requests[] = ['system' => $system, 'user' => $user];
            $isReview = str_contains($system, 'reviewer');

            $items = json_decode(substr($user, strpos($user, '[')), true);
            $reply = [];
            foreach ($items as $item) {
                foreach ($answers as $fragment => [$first, $second]) {
                    if (str_starts_with($item['name'], $fragment)) {
                        $reply[$item['id']] = ['allergens' => (object) ($isReview ? $second : $first), 'unsure' => false];
                    }
                }
            }

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => json_encode($reply)]]]]));
        });

        $classifier = new GroqAllergenClassifier(new AIProviderFactory($http, dirname(__DIR__, 3)), static fn () => null);

        return new IngredientAllergenBackfill($this->em, static::getContainer()->get(AllergenRepository::class), $classifier, new AllergenKeywordRules(), new AllergenReviewQueue($this->em));
    }

    /**
     * @param array<string, string> $links allergen code => source
     * @param string|null $reviewedBy defaults to 'yaml' when there are links
     */
    private function global(string $name, array $links = [], ?string $reviewedBy = null): int
    {
        $ingredient = new GlobalIngredient();
        $ingredient->setCode('backfill-test-' . uniqid());
        $translation = new GlobalIngredientTranslation();
        $translation->setLocale('en');
        $translation->setName($name);
        $ingredient->addTranslation($translation);
        $this->em->persist($translation);
        foreach ($links as $code => $source) {
            $link = new GlobalIngredientAllergen();
            $link->setAllergen($this->allergen($code));
            $link->setPresence(AllergenPresence::CONTAINS);
            $link->setSource($source);
            $ingredient->addAllergenLink($link);
            $this->em->persist($link);
        }
        $reviewedBy ??= $links !== [] ? (in_array(GlobalIngredientAllergen::SOURCE_AI, $links, true) ? GlobalIngredientAllergen::SOURCE_AI : GlobalIngredientAllergen::SOURCE_YAML) : null;
        if ($reviewedBy !== null) {
            $ingredient->markAllergensReviewed($reviewedBy);
        }
        $this->em->persist($ingredient);
        $this->em->flush();
        $this->globalIds[] = $ingredient->getId();

        return $ingredient->getId();
    }

    /** @param array<string, string> $names locale => name */
    private function private(Restaurant $restaurant, array $names, ?string $handSetAllergen = null): int
    {
        $ingredient = new Ingredient();
        $ingredient->setCode('backfill-test-' . uniqid());
        $ingredient->setRestaurant($restaurant);
        foreach ($names as $locale => $name) {
            $translation = new IngredientTranslation();
            $translation->setLocale($locale);
            $translation->setName($name);
            $ingredient->addTranslation($translation);
            $this->em->persist($translation);
        }
        if ($handSetAllergen !== null) {
            $link = new IngredientAllergen();
            $link->setAllergen($this->allergen($handSetAllergen));
            $link->setPresence(AllergenPresence::CONTAINS);
            $ingredient->addAllergenLink($link);
            $this->em->persist($link);
        }
        $this->em->persist($ingredient);
        $this->em->flush();

        return $ingredient->getId();
    }

    private function allergen(string $code): Allergen
    {
        return $this->em->getRepository(Allergen::class)->findOneBy(['code' => $code]);
    }
}
