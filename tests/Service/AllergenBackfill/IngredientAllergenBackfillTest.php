<?php

namespace App\Tests\Service\AllergenBackfill;

use App\Entity\Allergen;
use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientAllergen;
use App\Entity\GlobalIngredientTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientAllergen;
use App\Entity\IngredientTranslation;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Repository\AllergenRepository;
use App\Service\AI\AIProviderFactory;
use App\Service\AllergenBackfill\AllergenDecision;
use App\Service\AllergenBackfill\AllergenKeywordRules;
use App\Service\AllergenBackfill\GroqAllergenClassifier;
use App\Service\AllergenBackfill\IngredientAllergenBackfill;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The real backfill service against qrmenu_test, with the Groq API played
 * by a MockHttpClient that answers by ingredient name — no request can
 * reach a real API. Checks the write rules: only unambiguous answers are
 * written (source/reviewed_by 'ai'), YAML/hand-set ingredients are never
 * touched, and private ingredients are sent in their own language.
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

    public function testOnlyUnambiguousAnswersAreWrittenAndProtectedOnesNeverAre(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tag = uniqid();

        $egg = $this->global("Backfill egg {$tag}");
        $water = $this->global("Backfill water {$tag}");
        $wine = $this->global("Backfill wine {$tag}");
        $split = $this->global("Backfill mystery sauce {$tag}");
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

        // Name fragment => [first pass, review pass]
        $answers = [
            'Backfill egg' => [['eggs' => 'contains'], ['eggs' => 'contains']],
            'Backfill water' => [[], []],
            'Backfill wine' => [['sulphites' => 'may_contain'], ['sulphites' => 'may_contain']],
            'Backfill mystery sauce' => [['milk' => 'contains'], ['milk' => 'contains', 'eggs' => 'contains']],
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
        self::assertCount(7, $subjects);

        $outcomes = [];
        foreach ($backfill->run($subjects, 20, false) as $result) {
            $outcomes[explode(' ' . $tag, $result['subject']['name'])[0]] = $result['decision']->outcome;
        }

        self::assertSame(AllergenDecision::APPLIED, $outcomes['Backfill egg']);
        self::assertSame(AllergenDecision::NO_ALLERGENS, $outcomes['Backfill water']);
        self::assertSame(AllergenDecision::REVIEW, $outcomes['Backfill wine']);
        self::assertSame(AllergenDecision::REVIEW, $outcomes['Backfill mystery sauce']);
        self::assertSame(AllergenDecision::SUGGESTION, $outcomes['Backfill soy sauce']);
        self::assertSame(AllergenDecision::APPLIED, $outcomes['ametllat']);
        self::assertSame(AllergenDecision::SUGGESTION, $outcomes['huevo poche']);

        // Exactly two AI calls: one classify, one review, for all seven.
        self::assertCount(2, $this->requests);
        self::assertStringContainsString('"name":"ametllat ' . $tag . '","lang":"ca"', $this->requests[0]['user']);
        self::assertStringContainsString('reviewer', $this->requests[1]['system']);

        $this->em->clear();

        $egg = $this->em->find(GlobalIngredient::class, $egg);
        self::assertSame('ai', $egg->getAllergensReviewedBy());
        self::assertCount(1, $egg->getAllergenLinks());
        self::assertSame('ai', $egg->getAllergenLinks()->first()->getSource());

        $water = $this->em->find(GlobalIngredient::class, $water);
        self::assertSame('ai', $water->getAllergensReviewedBy());
        self::assertCount(0, $water->getAllergenLinks());

        foreach ([$wine, $split] as $id) {
            $ingredient = $this->em->find(GlobalIngredient::class, $id);
            self::assertNull($ingredient->getAllergensReviewedAt(), 'review items stay unreviewed');
            self::assertCount(0, $ingredient->getAllergenLinks());
        }

        $soySauce = $this->em->find(GlobalIngredient::class, $soySauce);
        self::assertCount(1, $soySauce->getAllergenLinks(), 'YAML ingredient untouched');
        self::assertSame('yaml', $soySauce->getAllergenLinks()->first()->getSource());

        $almond = $this->em->find(Ingredient::class, $almond);
        self::assertSame('ai', $almond->getAllergensReviewedBy());
        self::assertSame('nuts', $almond->getAllergenLinks()->first()->getAllergen()->getCode());

        $handSet = $this->em->find(Ingredient::class, $handSet);
        self::assertNull($handSet->getAllergensReviewedAt(), 'hand-set ingredient untouched');
        self::assertCount(1, $handSet->getAllergenLinks());
        self::assertSame('gluten', $handSet->getAllergenLinks()->first()->getAllergen()->getCode());

        // A second run skips everything already reviewed or protected.
        $again = array_filter(
            $backfill->findSubjects(array_map(fn (int $id) => $this->em->find(GlobalIngredient::class, $id)->getCode(), $this->globalIds), [$this->restaurantId], false),
            static fn (array $s) => str_contains($s['name'], $tag),
        );
        self::assertEqualsCanonicalizing(["Backfill mystery sauce {$tag}", "Backfill wine {$tag}"], array_column($again, 'name'));
    }

    public function testRecheckWithdrawsWhatTheCurrentRulesWouldSendToReview(): void
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
        $sauce = $this->private($restaurant, ['es' => "salsa de soja {$tag}"]);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $sauceEntity = $this->em->find(Ingredient::class, $sauce);
        foreach (['gluten', 'soybeans'] as $code) {
            $link = new IngredientAllergen();
            $link->setAllergen($this->allergen($code));
            $link->setPresence(AllergenPresence::CONTAINS);
            $link->setSource(IngredientAllergen::SOURCE_AI);
            $sauceEntity->addAllergenLink($link);
            $this->em->persist($link);
        }
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
            'Backfill crust' => AllergenDecision::REVIEW,
            'Backfill yeast extract' => AllergenDecision::REVIEW,
            'Backfill apple' => AllergenDecision::NO_ALLERGENS,
            'salsa de soja' => AllergenDecision::REVIEW,
        ], $outcomes, 'the YAML one is not even looked at');
        self::assertCount(0, $this->requests, 'no AI call');

        $this->em->clear();
        foreach ([$crust, $extract] as $id) {
            $ingredient = $this->em->find(GlobalIngredient::class, $id);
            self::assertNull($ingredient->getAllergensReviewedBy(), 'withdrawn: back to never reviewed');
            self::assertNull($ingredient->getAllergensReviewedAt());
            self::assertCount(0, $ingredient->getAllergenLinks());
        }
        self::assertSame('ai', $this->em->find(GlobalIngredient::class, $egg)->getAllergensReviewedBy());
        self::assertCount(1, $this->em->find(GlobalIngredient::class, $egg)->getAllergenLinks());
        self::assertSame('ai', $this->em->find(GlobalIngredient::class, $apple)->getAllergensReviewedBy());
        self::assertSame('yaml', $this->em->find(GlobalIngredient::class, $yamlSauce)->getAllergensReviewedBy());

        $sauceEntity = $this->em->find(Ingredient::class, $sauce);
        self::assertNull($sauceEntity->getAllergensReviewedBy());
        self::assertCount(0, $sauceEntity->getAllergenLinks());
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

        return new IngredientAllergenBackfill($this->em, static::getContainer()->get(AllergenRepository::class), $classifier, new AllergenKeywordRules());
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
