<?php

namespace App\Tests\Service\AllergenBackfill;

use App\Service\AllergenBackfill\AllergenDecision;
use App\Service\AllergenBackfill\AllergenKeywordRules;
use PHPUnit\Framework\TestCase;

final class AllergenDecisionTest extends TestCase
{
    private const CODES = ['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soybeans', 'milk', 'nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'];

    private static function pass(array $allergens, bool $unsure = false): array
    {
        ksort($allergens);

        return ['allergens' => $allergens, 'unsure' => $unsure];
    }

    private static function rules(array $expected = [], array $forbidden = []): array
    {
        return ['expected' => $expected, 'forbidden' => $forbidden, 'root' => 'x'];
    }

    public function testAgreementWithRulesAndOnlyContainsIsApplied(): void
    {
        $d = AllergenDecision::decide(self::pass(['eggs' => 'contains']), self::pass(['eggs' => 'contains']), self::rules(['eggs']), self::CODES, null);

        self::assertSame(AllergenDecision::APPLIED, $d->outcome);
        self::assertSame(['eggs' => 'contains'], $d->allergens);
    }

    public function testAgreedEmptySetIsReviewedWithNoAllergens(): void
    {
        $d = AllergenDecision::decide(self::pass([]), self::pass([]), self::rules(), self::CODES, null);

        self::assertSame(AllergenDecision::NO_ALLERGENS, $d->outcome);
    }

    public function testProcessedProductWithNoAllergensGoesToReview(): void
    {
        $rules = ['expected' => [], 'forbidden' => [], 'review' => [], 'processed' => 'extract', 'root' => 'x'];
        $d = AllergenDecision::decide(self::pass([]), self::pass([]), $rules, self::CODES, null);

        self::assertSame(AllergenDecision::REVIEW, $d->outcome);
        self::assertStringContainsString('«extract»', $d->reasons[0]);
    }

    public function testProcessedProductWithAllergensIsStillApplied(): void
    {
        $rules = ['expected' => ['eggs'], 'forbidden' => [], 'review' => [], 'processed' => 'powder', 'root' => 'x'];
        $d = AllergenDecision::decide(self::pass(['eggs' => 'contains']), self::pass(['eggs' => 'contains']), $rules, self::CODES, null);

        self::assertSame(AllergenDecision::APPLIED, $d->outcome);
    }

    public function testJuiceFromConcentrateWithNoAllergensIsApplied(): void
    {
        $rules = (new AllergenKeywordRules())->evaluate('Orange juice from concentrate');
        $d = AllergenDecision::decide(self::pass([]), self::pass([]), $rules, self::CODES, null);

        self::assertSame(AllergenDecision::NO_ALLERGENS, $d->outcome);
    }

    public function testDriedFruitWithNoAllergensGoesToReview(): void
    {
        foreach (['Dried fig', 'Raisins', 'Orejones'] as $name) {
            $d = AllergenDecision::decide(self::pass([]), self::pass([]), (new AllergenKeywordRules())->evaluate($name), self::CODES, null);

            self::assertSame(AllergenDecision::REVIEW, $d->outcome, $name);
        }
    }

    public function testParseIsTheReverseOfFormat(): void
    {
        self::assertSame([], AllergenDecision::parse('ninguno'));
        self::assertNull(AllergenDecision::parse('—'));
        $allergens = ['gluten' => 'contains', 'sulphites' => 'may_contain'];
        self::assertSame($allergens, AllergenDecision::parse(AllergenDecision::format($allergens)));
    }

    public function testPassesDisagreeingGoesToReview(): void
    {
        $d = AllergenDecision::decide(self::pass(['milk' => 'contains']), self::pass(['milk' => 'contains', 'eggs' => 'contains']), self::rules(), self::CODES, null);

        self::assertSame(AllergenDecision::REVIEW, $d->outcome);
        self::assertStringContainsString('no coinciden', $d->reasons[0]);
    }

    public function testMayContainAlwaysGoesToReview(): void
    {
        $d = AllergenDecision::decide(self::pass(['sulphites' => 'may_contain']), self::pass(['sulphites' => 'may_contain']), self::rules(['sulphites']), self::CODES, null);

        self::assertSame(AllergenDecision::REVIEW, $d->outcome);
        self::assertContains('"puede contener"', $d->reasons);
    }

    public function testUnsureGoesToReview(): void
    {
        $d = AllergenDecision::decide(self::pass([], true), self::pass([]), self::rules(), self::CODES, null);

        self::assertSame(AllergenDecision::REVIEW, $d->outcome);
    }

    public function testRuleMissingAllergenGoesToReview(): void
    {
        $d = AllergenDecision::decide(self::pass([]), self::pass([]), self::rules(['milk']), self::CODES, null);

        self::assertSame(AllergenDecision::REVIEW, $d->outcome);
    }

    public function testRuleForbiddenAllergenGoesToReview(): void
    {
        $d = AllergenDecision::decide(self::pass(['milk' => 'contains']), self::pass(['milk' => 'contains']), self::rules([], ['milk']), self::CODES, null);

        self::assertSame(AllergenDecision::REVIEW, $d->outcome);
    }

    public function testUnknownCodeGoesToReview(): void
    {
        $d = AllergenDecision::decide(self::pass(['dairy' => 'contains']), self::pass(['dairy' => 'contains']), self::rules(), self::CODES, null);

        self::assertSame(AllergenDecision::REVIEW, $d->outcome);
    }

    public function testCompositeGoesToReviewEvenWhenEverythingAgrees(): void
    {
        $rules = ['expected' => [], 'forbidden' => [], 'review' => ['compuesto o de receta variable'], 'root' => 'x'];
        $d = AllergenDecision::decide(self::pass(['fish' => 'contains']), self::pass(['fish' => 'contains']), $rules, self::CODES, null);

        self::assertSame(AllergenDecision::REVIEW, $d->outcome);
        self::assertSame(['compuesto o de receta variable'], $d->reasons);
    }

    public function testMissingAnswerFails(): void
    {
        self::assertSame(AllergenDecision::FAILED, AllergenDecision::decide(self::pass([]), null, self::rules(), self::CODES, null)->outcome);
    }

    public function testProtectedIngredientOnlyEverGetsASuggestion(): void
    {
        $d = AllergenDecision::decide(
            self::pass(['gluten' => 'contains', 'soybeans' => 'contains']),
            self::pass(['gluten' => 'contains', 'soybeans' => 'contains']),
            self::rules(['soybeans']),
            self::CODES,
            ['soybeans' => 'contains'],
        );

        self::assertSame(AllergenDecision::SUGGESTION, $d->outcome);
        self::assertStringContainsString('añadir gluten (contains)', $d->reasons[0]);
    }

    public function testProtectedIngredientTheAiAgreesWithIsUnchanged(): void
    {
        $d = AllergenDecision::decide(self::pass(['milk' => 'contains']), self::pass(['milk' => 'contains']), self::rules(['milk']), self::CODES, ['milk' => 'contains']);

        self::assertSame(AllergenDecision::UNCHANGED, $d->outcome);
    }
}
