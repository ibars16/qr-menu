<?php

namespace App\Tests\Service;

use App\Service\CustomerNeeds;
use App\Service\SmartWaiterNeedsDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SmartWaiterNeedsDetector: the small call (no menu), its strict JSON —
 * validated on the server, anything else moves on to the next provider —
 * the same for Groq, Gemini and OpenAI. Fake AI only (SmartWaiterFakeAiTrait).
 */
final class SmartWaiterNeedsDetectorTest extends TestCase
{
    use SmartWaiterFakeAiTrait;

    protected function setUp(): void
    {
        $this->fakeAiSetUp();
    }

    protected function tearDown(): void
    {
        $this->fakeAiTearDown();
    }

    private function detector(): SmartWaiterNeedsDetector
    {
        return $this->needsDetector($this->factory());
    }

    public function testAValidAnswerBecomesCustomerNeeds(): void
    {
        $needs = $this->detector()->parse('{"avoid":["gluten","milk"],"diets":["vegan"],"allergy":true,"other":false}');

        self::assertEquals(new CustomerNeeds(['gluten', 'milk'], ['vegan'], true, false), $needs);
    }

    public function testACodeBlockAroundTheJsonAndUppercaseCodesAreFine(): void
    {
        $needs = $this->detector()->parse("```json\n{\"avoid\":[\" Gluten \"],\"diets\":[],\"allergy\":true,\"other\":false}\n```");

        self::assertEquals(new CustomerNeeds(['gluten'], [], true, false), $needs);
    }

    public function testAnUnknownCodeIsNeverGuessedItBecomesOther(): void
    {
        $needs = $this->detector()->parse('{"avoid":["wheat","milk"],"diets":["kosher"],"allergy":true,"other":false}');

        self::assertEquals(new CustomerNeeds(['milk'], [], true, true), $needs, 'nothing is confirmed for them');
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalid(): iterable
    {
        yield 'not JSON' => ['Eres celíaco, así que evita el gluten.'];
        yield 'a list' => ['["gluten"]'];
        yield 'a key missing' => ['{"avoid":["gluten"],"diets":[],"allergy":true}'];
        yield 'avoid not a list' => ['{"avoid":"gluten","diets":[],"allergy":true,"other":false}'];
        yield 'a code not a string' => ['{"avoid":[1],"diets":[],"allergy":true,"other":false}'];
        yield 'allergy not true/false' => ['{"avoid":[],"diets":[],"allergy":"yes","other":false}'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalid')]
    public function testAnythingElseIsInvalid(string $answer): void
    {
        self::assertNull($this->detector()->parse($answer));
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function languages(): iterable
    {
        yield 'a code' => ['"en"', 'en'];
        yield 'upper case' => ['"DE"', 'de'];
        yield 'with a region' => ['"zh-CN"', 'zh'];
        yield 'Norwegian Bokmål' => ['"nb"', 'no'];
        yield 'a name, not a code' => ['"Spanish"', null];
        yield 'not a string' => ['5', null];
    }

    #[DataProvider('languages')]
    public function testTheOptionalLanguage(string $value, ?string $expected): void
    {
        $answer = '{"avoid":[],"diets":[],"allergy":false,"other":false,"language":' . $value . '}';

        self::assertSame($expected, $this->detector()->language($answer));
        self::assertNotNull($this->detector()->parse($answer), 'an odd "language" never makes the answer invalid');
    }

    public function testNoLanguageIsFineToo(): void
    {
        self::assertNull($this->detector()->language(self::NO_NEEDS));
        self::assertNotNull($this->detector()->parse(self::NO_NEEDS));
        self::assertStringContainsString('"language": the two-letter ISO 639-1 code of the language the customer\'s latest message is written in', $this->detector()->request(['Hi'], null)->systemPrompt);
    }

    public function testDetectGivesTheLanguageToo(): void
    {
        $this->needsReplies = [self::GROQ => [[self::needs(['gluten'], [], true, false, 'en'), 'stop']]];

        [$needs, , $language] = $this->detector()->detect(['I am coeliac'], null);

        self::assertEquals(new CustomerNeeds(['gluten'], [], true), $needs);
        self::assertSame('en', $language);
    }

    public function testTheSmallCallCarriesNoMenuAndOnlyTheStartOfThePreviousReply(): void
    {
        $request = $this->detector()->request(['Soy celíaco', '¿Y de postre?'], 'Te recomiendo ' . str_repeat('x', 900));

        self::assertStringStartsWith(SmartWaiterNeedsDetector::PROMPT_START, $request->systemPrompt);
        self::assertStringNotContainsString('MENU DATA', $request->systemPrompt);
        self::assertStringContainsString('gluten, crustaceans, eggs, fish, peanuts, soybeans, milk, nuts (tree nuts), celery, mustard, sesame, sulphites, lupin, molluscs', $request->systemPrompt);
        self::assertStringContainsString('bread, pasta or beer → gluten', $request->systemPrompt);
        self::assertCount(1, $request->messages);

        $input = json_decode($request->messages[0]->content, true);
        self::assertSame(['Soy celíaco', '¿Y de postre?'], $input['customer_messages']);
        self::assertSame(SmartWaiterNeedsDetector::PREVIOUS_REPLY_CHARS, mb_strlen($input['waiter_previous_reply']));
        self::assertSame(SmartWaiterNeedsDetector::MAX_TOKENS, $request->maxTokens);
    }

    public function testEveryExampleIsInTheInstructionAndIsWhatParseAccepts(): void
    {
        $system = $this->detector()->request(['Hola'], null)->systemPrompt;
        self::assertStringEndsWith("Examples:\n" . '"No puedo comer pasta" → {"avoid":["gluten"],"diets":[],"allergy":true,"other":false,"language":"es"}' . "\n"
            . '"I\'m vegan and my son is allergic to nuts" → {"avoid":["nuts"],"diets":["vegan"],"allergy":true,"other":false,"language":"en"}' . "\n"
            . '"Je suis allergique au kiwi" → {"avoid":[],"diets":[],"allergy":true,"other":true,"language":"fr"}' . "\n"
            . '"¿La pizza lleva gluten?" → {"avoid":[],"diets":[],"allergy":false,"other":false,"language":"es"}', $system);

        $expected = [
            'No puedo comer pasta' => [new CustomerNeeds(['gluten'], [], true), 'es'],
            "I'm vegan and my son is allergic to nuts" => [new CustomerNeeds(['nuts'], ['vegan'], true), 'en'],
            'Je suis allergique au kiwi' => [new CustomerNeeds([], [], true, true), 'fr'],
            '¿La pizza lleva gluten?' => [new CustomerNeeds(), 'es'],
        ];
        self::assertSame(array_keys($expected), array_keys(SmartWaiterNeedsDetector::EXAMPLES));
        foreach (SmartWaiterNeedsDetector::EXAMPLES as $message => $json) {
            self::assertEquals($expected[$message][0], $this->detector()->parse($json), $message . ': never an example our own parse() would read otherwise');
            self::assertSame($expected[$message][1], $this->detector()->language($json), $message);
        }
    }

    public function testCantEatCountsAsAnAllergyTasteOrDietDoesNot(): void
    {
        $system = $this->detector()->request(['Hola'], null)->systemPrompt;

        self::assertStringContainsString('- "allergy": true if they say they, or someone they are ordering for, have an allergy or an intolerance (coeliac included), can\'t eat or drink something, or that something makes them unwell. Avoiding something by taste or because of a diet (e.g. "I\'d rather have no meat", "I\'m vegan") is not an allergy.', $system);
        self::assertStringNotContainsString('"allergy": true only if', $system);
    }

    public function testTheTestCsvsOwnWordingIsNotAnExample(): void
    {
        self::assertStringNotContainsString('alérgico al pan', $this->detector()->request(['Hola'], null)->systemPrompt, 'so the CSV run measures the detection');
    }

    public function testWithoutAPreviousReplyThereIsNoContext(): void
    {
        $input = json_decode($this->detector()->request(['Soy celíaco'], null)->messages[0]->content, true);

        self::assertSame(['customer_messages' => ['Soy celíaco']], $input);
    }

    public function testGroqAnswersFirst(): void
    {
        $this->needsReplies = [self::GROQ => [[self::needs(['gluten'], [], true), 'stop']]];

        [$needs, $attempts] = $this->detector()->detect(['Soy celíaco'], null);

        self::assertEquals(new CustomerNeeds(['gluten'], [], true), $needs);
        self::assertSame([], $attempts);
        self::assertSame([self::GROQ], array_column($this->detections, 'host'));
        self::assertSame([], $this->requests, 'the main call is never made by the detector');
    }

    public function testAnInvalidAnswerMovesOnToGeminiThenOpenAi(): void
    {
        $this->needsReplies = [
            self::GROQ => [['Eres celíaco: evita el gluten.', 'stop']],
            self::GEMINI => [['{"avoid":"gluten"}', 'STOP']],
            self::OPENAI => [["```json\n" . self::needs(['gluten'], [], true) . "\n```", 'stop']],
        ];

        [$needs, $attempts] = $this->detector()->detect(['Soy celíaco'], null);

        self::assertEquals(new CustomerNeeds(['gluten'], [], true), $needs);
        self::assertSame([self::GROQ, self::GEMINI, self::OPENAI], array_column($this->detections, 'host'));
        self::assertCount(2, $attempts);
    }

    public function testGeminiAnswersWhenGroqIsRateLimited(): void
    {
        $this->needsReplies = [
            self::GROQ => [[null, 429]],
            self::GEMINI => [[self::needs([], ['vegan']), 'STOP']],
        ];

        [$needs] = $this->detector()->detect(['Soy vegana'], null);

        self::assertEquals(new CustomerNeeds([], ['vegan']), $needs);
    }

    public function testACutOffAnswerIsNotUsed(): void
    {
        $this->needsReplies = [
            self::GROQ => [['{"avoid":["glu', 'length']],
            self::GEMINI => [[self::needs(['gluten'], [], true), 'STOP']],
        ];

        [$needs] = $this->detector()->detect(['Soy celíaco'], null);

        self::assertEquals(new CustomerNeeds(['gluten'], [], true), $needs);
        self::assertSame([self::GROQ, self::GEMINI], array_column($this->detections, 'host'));
    }

    public function testEveryProviderFailingGivesNoNeeds(): void
    {
        $this->needsReplies = [self::GROQ => [[null, 500]], self::GEMINI => [[null, 500]], self::OPENAI => [['no', 'stop']]];

        [$needs, $attempts] = $this->detector()->detect(['Soy celíaco'], null);

        self::assertNull($needs);
        self::assertCount(3, $attempts);
    }

    public function testTheSameRequestShapeReachesGeminiGroqAndOpenAi(): void
    {
        $this->needsReplies = [self::GROQ => [['x', 'stop']], self::GEMINI => [['x', 'STOP']], self::OPENAI => [['x', 'stop']]];
        $this->detector()->detect(['Soy celíaco'], 'Hola');

        $systems = [];
        foreach ($this->detections as $call) {
            $systems[] = $this->systemText($call);
            self::assertSame([['user', '{"waiter_previous_reply":"Hola","customer_messages":["Soy celíaco"]}']], $this->sentTurns($call));
        }
        self::assertCount(3, $systems);
        self::assertCount(1, array_unique($systems), 'one instruction for every provider');
    }
}
