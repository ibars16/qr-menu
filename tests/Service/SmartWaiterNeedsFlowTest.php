<?php

namespace App\Tests\Service;

use App\Service\SmartWaiterConversationStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The whole Smart Waiter answer with needs detection in front: detect →
 * sort the dishes → answer. Fake AI only (SmartWaiterFakeAiTrait); the
 * small call is scripted with $needsReplies, the main one with $replies.
 */
final class SmartWaiterNeedsFlowTest extends TestCase
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

    /** @return array<string, mixed> what the small call received as the customer's input */
    private function detectionInput(array $call): array
    {
        return json_decode($this->sentTurns($call)[0][1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testWhenEveryDetectionFailsThereIsNoAnswerAndTheMessageIsCheckedAgainNextTime(): void
    {
        $service = $this->service();
        $this->needsReplies = [self::GROQ => [[null, 500]], self::GEMINI => [[null, 500]], self::OPENAI => [['no sé', 'stop']]];
        $this->replies = [self::GROQ => [['Tenemos pizzas.', 'stop'], ['Respuesta.', 'stop']]];

        $first = $service->reply($this->restaurant(), 'es', 'EUR', null, 'Soy celíaco');

        self::assertTrue($first['error'], 'the safe side: the usual fallback');
        self::assertNull($first['reply']);
        self::assertSame([], $this->requests, 'never answered with dishes without checking');
        self::assertFalse($this->logs[0]->isSuccess());

        $this->needsReplies = [self::GROQ => [[self::needs(['gluten'], [], true), 'stop']]];
        $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], '¿Hay algo picante?');

        self::assertSame(['Soy celíaco', '¿Hay algo picante?'], $this->detectionInput(end($this->detections))['customer_messages'], 'the failed one is detected again');
        $main = end($this->requests);
        self::assertStringContainsString("- avoids: gluten\n", $this->systemText($main));
        self::assertSame(['Soy celíaco'], $this->earlierCustomerMessages($main), 'and it was still said');
    }

    public function testNeedsAddUpAndAreNeverForgotten(): void
    {
        $service = $this->service();
        $this->needsReplies = [self::GROQ => [
            [self::needs(['gluten'], [], true), 'stop'],
            [self::needs(['milk']), 'stop'],
            [self::needs(), 'stop'],
        ]];
        $this->replies = [self::GROQ => array_fill(0, 3, ['Vale.', 'stop'])];

        $id = $service->reply($this->restaurant(), 'es', 'EUR', null, 'Soy celíaco')['conversationId'];
        $service->reply($this->restaurant(), 'es', 'EUR', $id, 'Y sin leche, por favor');
        $service->reply($this->restaurant(), 'es', 'EUR', $id, '¿Qué postres hay?');

        $system = $this->systemText(end($this->requests));
        self::assertStringContainsString("- avoids: gluten, milk\n", $system, 'a question with nothing new keeps everything');
        self::assertStringContainsString('- STAFF: never mention the staff', $system, 'the allergy said in the first message still counts');
    }

    public function testTheSmallCallSeesTheWaitersPreviousReplyAsContext(): void
    {
        $service = $this->service();
        $this->replies = [self::GROQ => [['¿Tienes alguna alergia?', 'stop'], ['Vale.', 'stop']]];

        $id = $service->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué me recomiendas?')['conversationId'];
        $service->reply($this->restaurant(), 'es', 'EUR', $id, 'Sí, al gluten');

        self::assertArrayNotHasKey('waiter_previous_reply', $this->detectionInput($this->detections[0]));
        self::assertSame(['waiter_previous_reply' => '¿Tienes alguna alergia?', 'customer_messages' => ['Sí, al gluten']], $this->detectionInput(end($this->detections)));
    }

    public function testTheStaffSentenceFollowsTheDetectorsAllergyOnly(): void
    {
        foreach ([
            'nothing' => [self::needs(), false, false],
            'a diet' => [self::needs([], ['vegan']), false, true],
            'an allergy' => [self::needs(['gluten'], [], true), true, true],
        ] as $case => [$detected, $staff, $checked]) {
            $this->requests = [];
            $this->needsReplies = [self::GROQ => [[$detected, 'stop']]];
            $this->replies = [self::GROQ => [['Vale.', 'stop']]];

            $this->service()->reply($this->restaurant(), 'es', 'EUR', null, 'Hola');

            $system = $this->systemText(end($this->requests));
            self::assertSame($staff, str_contains($system, '- STAFF: never mention the staff'), $case);
            self::assertSame(!$staff, str_contains($system, '- STAFF: never advise checking with the staff'), $case);
            self::assertSame($checked, str_contains($system, 'CHECKED FOR THIS CUSTOMER'), $case);
        }
    }

    private const CONFIRM_ES = 'Antes de pedir, conviene confirmarlo con el personal.';

    public function testWithAnAllergyOurCodeEndsEveryReplyWithTheStaffSentence(): void
    {
        $service = $this->service();
        $this->needsReplies = [self::GROQ => [[self::needs(['gluten'], [], true), 'stop'], [self::needs(), 'stop']]];
        $this->replies = [self::GROQ => [['Tenemos carpaccio, aunque la carta no dice si lleva gluten.', 'stop'], ['No tenemos vino tinto.', 'stop']]];

        $first = $service->reply($this->restaurant(), 'es', 'EUR', null, 'Soy celíaco');
        // The test menu is empty: no dish is suitable.
        self::assertSame("Tenemos carpaccio, aunque la carta no dice si lleva gluten.\n\nNingún plato de nuestra carta está confirmado sin gluten. " . self::CONFIRM_ES, $first['reply']);

        $wine = $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], '¿Tenéis vino tinto?');
        self::assertStringEndsWith("No tenemos vino tinto.\n\nNingún plato de nuestra carta está confirmado sin gluten. " . self::CONFIRM_ES, $wine['reply'], 'every reply, the wine one too');
    }

    public function testTheStaffSentenceIsNeverKeptNorShownToTheDetector(): void
    {
        $service = $this->service();
        $this->needsReplies = [self::GROQ => [[self::needs(['gluten'], [], true), 'stop'], [self::needs(), 'stop']]];
        $this->replies = [self::GROQ => [['Vale.', 'stop'], ['Vale.', 'stop']]];

        $id = $service->reply($this->restaurant(), 'es', 'EUR', null, 'Soy celíaco')['conversationId'];
        $service->reply($this->restaurant(), 'es', 'EUR', $id, '¿Y de postre?');

        self::assertSame([['user', 'Soy celíaco'], ['assistant', 'Vale.'], ['user', '¿Y de postre?']], $this->sentTurns(end($this->requests)), 'the AI never sees it as its own words');
        self::assertSame('Vale.', $this->detectionInput(end($this->detections))['waiter_previous_reply']);
        self::assertSame('Vale.', $this->store->getLastReply($id, 4242));
    }

    public function testNoAllergyNoStaffSentence(): void
    {
        foreach (['nothing' => self::needs(), 'a diet' => self::needs([], ['vegan']), 'avoids, no allergy' => self::needs(['milk'])] as $case => $detected) {
            $this->needsReplies = [self::GROQ => [[$detected, 'stop']]];
            $this->replies = [self::GROQ => [['Vale.', 'stop']]];

            self::assertSame('Vale.', $this->service()->reply($this->restaurant(), 'es', 'EUR', null, 'Hola')['reply'], $case);
        }
    }

    public function testTheStaffSentenceIsInTheCustomersLanguageElseThePages(): void
    {
        $this->needsReplies = [self::GROQ => [[self::needs(['gluten'], [], true, false, 'en'), 'stop']]];
        $this->replies = [self::GROQ => [['We have carpaccio.', 'stop']]];
        $english = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, 'I am coeliac');
        self::assertSame("We have carpaccio.\n\nNo dish on our menu is confirmed free of gluten. Before ordering, it's best to confirm with the staff.", $english['reply'], 'a Spanish page, an English customer');

        $this->needsReplies = [self::GROQ => [[self::needs(['gluten'], [], true, false, 'xx'), 'stop']]];
        $this->replies = [self::GROQ => [['Vale.', 'stop']]];
        self::assertStringEndsWith(self::CONFIRM_ES, $this->service()->reply($this->restaurant(), 'es', 'EUR', null, 'Soy celíaco')['reply'], 'an unknown one: the page\'s');
    }

    public function testTheDetectedLanguageIsNamedInTheMainInstruction(): void
    {
        $this->needsReplies = [self::GROQ => [[self::needs([], [], false, false, 'en'), 'stop']]];
        $this->replies = [self::GROQ => [['We have flan.', 'stop']]];

        $this->service()->reply($this->restaurant(), 'es', 'EUR', null, 'What do you recommend for dessert?');

        $system = $this->systemText(end($this->requests));
        self::assertStringContainsString('The customer\'s latest message is in English: reply in English', $system, 'a Spanish page, an English customer');
        self::assertStringContainsString('- Reply in English (rule 10).', $system);

        $this->requests = [];
        $this->needsReplies = [self::GROQ => [[self::needs(), 'stop']]];
        $this->replies = [self::GROQ => [['Vale.', 'stop']]];
        $this->service()->reply($this->restaurant(), 'es', 'EUR', null, 'Hola');
        self::assertStringContainsString('10. Reply in the same language the customer is writing in', $this->systemText(end($this->requests)), 'no language given: rule 10 as it was');
    }

    public function testTheReplyIsCapitalisedBeforeTheSentenceIsAdded(): void
    {
        $this->needsReplies = [self::GROQ => [[self::needs(['gluten'], [], true), 'stop']]];
        $this->replies = [self::GROQ => [['esa pizza no es adecuada para ti. lleva gluten.', 'stop']]];

        $reply = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Y la pizza?')['reply'];

        self::assertStringStartsWith("Esa pizza no es adecuada para ti. Lleva gluten.\n\n", $reply);
    }

    public function testDetectionThenAnswerTheSameForGeminiGroqAndOpenAi(): void
    {
        $systems = [];
        foreach ([self::GROQ => 'stop', self::GEMINI => 'STOP', self::OPENAI => 'stop'] as $host => $finish) {
            $this->requests = [];
            $this->detections = [];
            $this->store = new SmartWaiterConversationStore(new ArrayAdapter(), new NullLogger());
            // Only this provider answers, both calls; every other one gets a 500.
            $this->needsReplies = [self::GROQ => [[null, 500]], self::GEMINI => [[null, 500]], self::OPENAI => [[null, 500]]];
            $this->needsReplies[$host] = [[self::needs(['gluten'], [], true, false, 'es'), $finish]];
            $this->replies = [$host => [['Vale.', $finish]]];

            $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, 'Soy celíaco');

            self::assertSame("Vale.\n\nNingún plato de nuestra carta está confirmado sin gluten. " . self::CONFIRM_ES, $result['reply'], $host . ': the same fixed sentence');
            self::assertSame($host, end($this->detections)['host']);
            $systems[$host] = $this->systemText(end($this->requests));
        }

        self::assertCount(1, array_unique($systems), 'the very same sorted instruction for every provider');
        self::assertStringContainsString('reply in Spanish', $systems[self::GROQ], 'the language named for all three alike');
    }
}
