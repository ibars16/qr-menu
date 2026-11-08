<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * What the AI actually sees of the conversation: every earlier customer
 * message and reply goes as its own turn, before the new question, in each
 * provider's own roles — Gemini "user"/"model", Groq/OpenAI
 * "user"/"assistant" — so an allergy the customer mentioned earlier is in
 * front of the model when it recommends. And the current limit: only the
 * last 12 messages (6 exchanges) are kept
 * (SmartWaiterConversationStore::MAX_HISTORY_MESSAGES).
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

    public function testOnlyTheLastSixExchangesAreSentSoAnOlderAllergyIsNoLongerSeen(): void
    {
        $service = $this->service();
        $this->replies = [self::GEMINI => array_fill(0, 8, ['Respuesta.', 'STOP'])];

        $conversationId = $service->reply($this->restaurant(), 'es', 'EUR', null, self::ALLERGY)['conversationId'];
        for ($i = 1; $i <= 6; $i++) {
            $service->reply($this->restaurant(), 'es', 'EUR', $conversationId, 'Pregunta ' . $i);
        }
        $service->reply($this->restaurant(), 'es', 'EUR', $conversationId, self::FOLLOW_UP);

        $turns = $this->sentTurns(end($this->requests));
        self::assertCount(13, $turns, '12 earlier messages + the new question');
        self::assertSame(['user', 'Pregunta 1'], $turns[0]);
        self::assertNotContains(['user', self::ALLERGY], $turns, 'documents today\'s limit: an allergy 7 questions back is gone');
    }
}
