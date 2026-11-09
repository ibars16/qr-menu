<?php

namespace App\Tests\Service;

use App\Service\SmartWaiterConversationStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * What the AI actually sees of the conversation: every earlier customer
 * message and reply goes as its own turn, before the new question, in each
 * provider's own roles — Gemini "user"/"model", Groq/OpenAI
 * "user"/"assistant" — so an allergy the customer mentioned earlier is in
 * front of the model when it recommends. Past the last 12 messages
 * (SmartWaiterConversationStore::MAX_HISTORY_MESSAGES), and for a question
 * no provider answered, the customer's own words still reach the AI in the
 * instruction's EARLIER CUSTOMER MESSAGES block — identical for every
 * provider.
 *
 * Fake AI, real pipeline: see SmartWaiterFakeAiTrait.
 */
final class SmartWaiterHistoryTest extends TestCase
{
    use SmartWaiterFakeAiTrait;

    private const ALLERGY = 'Soy alérgico al pan, ¿puedo tomar el carpaccio de salmón?';
    private const FIRST_REPLY = 'La carta no indica si el carpaccio de salmón lleva pan. Confírmalo con el personal antes de pedir.';
    private const FOLLOW_UP = '¿Y qué me recomiendas?';

    protected function setUp(): void
    {
        $this->fakeAiSetUp();
    }

    protected function tearDown(): void
    {
        $this->fakeAiTearDown();
    }

    public function testGeminiSeesTheEarlierAllergyAsACustomerTurn(): void
    {
        $service = $this->service();
        $this->replies = [self::GEMINI => [[self::FIRST_REPLY, 'STOP'], ['Ningún plato está confirmado sin gluten.', 'STOP']]];

        $first = $service->reply($this->restaurant(), 'es', 'EUR', null, self::ALLERGY);
        $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], self::FOLLOW_UP);

        $last = end($this->requests);
        self::assertSame(self::GEMINI, $last['host']);
        self::assertSame([
            ['user', self::ALLERGY],
            ['model', self::FIRST_REPLY],
            ['user', self::FOLLOW_UP],
        ], $this->sentTurns($last));
        self::assertStringNotContainsString(self::ALLERGY, $last['body']['systemInstruction']['parts'][0]['text'], 'history travels as turns, not inside the instruction');
    }

    public function testGroqAndOpenAiSeeTheEarlierAllergyAsAUserMessage(): void
    {
        $service = $this->service();
        // Gemini unscripted → 500 → Groq answers both questions.
        $this->replies = [self::GROQ => [[self::FIRST_REPLY, 'stop'], ['Ningún plato está confirmado sin gluten.', 'stop']]];

        $first = $service->reply($this->restaurant(), 'es', 'EUR', null, self::ALLERGY);
        $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], self::FOLLOW_UP);

        $last = end($this->requests);
        self::assertSame(self::GROQ, $last['host']);
        $expected = [
            ['user', self::ALLERGY],
            ['assistant', self::FIRST_REPLY],
            ['user', self::FOLLOW_UP],
        ];
        self::assertSame($expected, $this->sentTurns($last));
        self::assertSame('system', $last['body']['messages'][0]['role'], 'the instruction goes first, as the system message');

        // OpenAI uses the very same adapter and shape: the same conversation, answered by it.
        $this->requests = [];
        $this->replies = [self::OPENAI => [['Ningún plato está confirmado sin gluten.', 'stop']]];
        $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], 'Somos varios y uno de nosotros es alérgico al pan, así que no queremos arriesgarnos con nada que pueda llevarlo, ¿qué platos de la carta nos podrías recomendar para compartir?');
        $openAi = end($this->requests);
        self::assertSame(self::OPENAI, $openAi['host']);
        self::assertSame(['user', self::ALLERGY], $this->sentTurns($openAi)[0]);
    }

    public function testAShortConversationCarriesNoEarlierMessagesBlock(): void
    {
        $service = $this->service();
        $this->replies = [self::GEMINI => array_fill(0, 6, ['Respuesta.', 'STOP'])];

        $conversationId = $service->reply($this->restaurant(), 'es', 'EUR', null, self::ALLERGY)['conversationId'];
        for ($i = 1; $i <= 5; $i++) {
            $service->reply($this->restaurant(), 'es', 'EUR', $conversationId, 'Pregunta ' . $i);
        }

        $last = end($this->requests);
        self::assertCount(11, $this->sentTurns($last), 'the 6th question: 5 earlier exchanges + itself, all as turns');
        self::assertNull($this->earlierCustomerMessages($last));
        self::assertStringNotContainsString('EARLIER CUSTOMER MESSAGES', $this->systemText($last));
    }

    /** The old limit, closed: an allergy said 7 questions back left the turns but still reaches the AI — same block for every provider. */
    public function testAnAllergySevenQuestionsBackStillReachesGeminiGroqAndOpenAi(): void
    {
        $blocks = [];
        foreach ([self::GEMINI => 'STOP', self::GROQ => 'stop', self::OPENAI => 'stop'] as $host => $finish) {
            $this->requests = [];
            $this->store = new SmartWaiterConversationStore(new ArrayAdapter(), new NullLogger());
            // Only this provider is scripted: every other one gets a 500 and is skipped.
            $this->replies = [$host => array_fill(0, 8, ['Respuesta.', $finish])];
            $service = $this->service();

            $conversationId = $service->reply($this->restaurant(), 'es', 'EUR', null, self::ALLERGY)['conversationId'];
            for ($i = 1; $i <= 6; $i++) {
                $service->reply($this->restaurant(), 'es', 'EUR', $conversationId, 'Pregunta ' . $i);
            }
            $service->reply($this->restaurant(), 'es', 'EUR', $conversationId, self::FOLLOW_UP);

            $last = end($this->requests);
            self::assertSame($host, $last['host']);
            $turns = $this->sentTurns($last);
            self::assertCount(13, $turns, '12 recent messages + the new question');
            self::assertSame('Pregunta 1', $turns[0][1]);
            self::assertNotContains(self::ALLERGY, array_column($turns, 1), 'no longer a turn…');
            self::assertSame([self::ALLERGY], $this->earlierCustomerMessages($last), '…but still in front of the AI');

            $system = $this->systemText($last);
            $blocks[$host] = substr($system, strpos($system, 'EARLIER CUSTOMER MESSAGES'));
        }

        self::assertCount(1, array_unique($blocks), 'the very same block for Gemini, Groq and OpenAI');
    }

    public function testTheBlockHoldsOnlyCustomerMessagesOldestFirstAndNeverRepeatsATurn(): void
    {
        $service = $this->service();
        $this->replies = [self::GEMINI => array_fill(0, 9, ['Respuesta del camarero.', 'STOP'])];

        $conversationId = $service->reply($this->restaurant(), 'es', 'EUR', null, 'Pregunta 0')['conversationId'];
        for ($i = 1; $i <= 8; $i++) {
            $service->reply($this->restaurant(), 'es', 'EUR', $conversationId, 'Pregunta ' . $i);
        }

        $last = end($this->requests);
        self::assertSame(['Pregunta 0', 'Pregunta 1'], $this->earlierCustomerMessages($last));
        $turnTexts = array_column($this->sentTurns($last), 1);
        self::assertSame('Pregunta 2', $turnTexts[0]);
        self::assertNotContains('Pregunta 0', $turnTexts);
        self::assertNotContains('Pregunta 1', $turnTexts);
    }

    public function testAQuestionNoProviderAnsweredStillReachesTheNextRequest(): void
    {
        $service = $this->service();
        // First question: nothing scripted — Gemini, Groq and OpenAI all fail.
        $failed = $service->reply($this->restaurant(), 'es', 'EUR', null, self::ALLERGY);
        self::assertTrue($failed['error']);

        $this->replies = [self::GEMINI => [['Ningún plato está confirmado sin gluten.', 'STOP']]];
        $service->reply($this->restaurant(), 'es', 'EUR', $failed['conversationId'], self::FOLLOW_UP);

        $last = end($this->requests);
        self::assertSame([['user', self::FOLLOW_UP]], $this->sentTurns($last), 'turns still alternate: the unanswered one isn\'t a turn');
        self::assertSame([self::ALLERGY], $this->earlierCustomerMessages($last));
    }
}
