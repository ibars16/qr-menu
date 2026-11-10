<?php

namespace App\Tests\Service;

use App\Service\AI\AIFailureReason;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Smart Waiter never shows, nor keeps in the conversation, an answer that
 * didn't end normally (AIResponse::endedNormally()): it moves on to the next
 * provider exactly as if that one had failed. Each provider is answered in
 * its own real response shape — Gemini's finishReason (STOP / MAX_TOKENS /
 * SAFETY…), Groq's and OpenAI's finish_reason (stop / length).
 *
 * Smart Waiter's order (smart_waiter_first): Groq → Gemini flash-lite → the
 * rest (OpenAI here), for simple and complex questions alike.
 *
 * Fake AI, real pipeline: see SmartWaiterFakeAiTrait.
 */
final class SmartWaiterCutOffTest extends TestCase
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

    public function testGroqCutOffMovesOnToGemini(): void
    {
        $this->replies = [
            self::GROQ => [['Sin gluten tienes la ensalada y', 'length']],
            self::GEMINI => [['Sin gluten tienes la ensalada.', 'STOP']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué hay sin gluten?');

        self::assertFalse($result['error']);
        self::assertSame('Sin gluten tienes la ensalada.', $result['reply']);
        self::assertSame([self::GROQ, self::GEMINI], $this->hosts());
        self::assertSame('gemini-flash-lite', $this->logs[0]->getProviderId());
        self::assertSame(2, $this->logs[0]->getAttemptsCount());
    }

    public function testGeminiCutOffMovesOnToTheNextProvider(): void
    {
        $this->replies = [
            self::GROQ => [['', 'stop']], // no usable text: an ordinary failure
            self::GEMINI => [['Tenemos tres postres: tarta', 'MAX_TOKENS']],
            self::OPENAI => [['Tenemos tres postres: tarta, flan y helado.', 'stop']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué postres hay?');

        self::assertSame('Tenemos tres postres: tarta, flan y helado.', $result['reply']);
        self::assertSame([self::GROQ, self::GEMINI, self::OPENAI], $this->hosts());
        self::assertSame('openai-mini', $this->logs[0]->getProviderId());
    }

    public function testAComplexQuestionAlsoGoesGroqThenGeminiThenOpenAi(): void
    {
        $this->replies = [
            self::GROQ => [['Para cuatro os sugiero', 'length']],
            self::GEMINI => [['', 'STOP']],
            self::OPENAI => [['Para cuatro os sugiero las verduras a la plancha.', 'stop']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, self::COMPLEX_MESSAGE);

        self::assertSame('Para cuatro os sugiero las verduras a la plancha.', $result['reply']);
        self::assertSame([self::GROQ, self::GEMINI, self::OPENAI], $this->hosts(), 'complex: smart_waiter_first too, then the capable tier');
        self::assertSame('complex', $this->logs[0]->getComplexity());
    }

    public function testGeminiStoppedForAnyOtherReasonIsNotUsedEither(): void
    {
        // Groq unscripted → 500, so Gemini is asked.
        $this->replies = [
            self::GEMINI => [['La pizza lleva', 'SAFETY']],
            self::OPENAI => [['La pizza lleva tomate y mozzarella.', 'stop']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué lleva la pizza?');

        self::assertSame('La pizza lleva tomate y mozzarella.', $result['reply']);
        self::assertSame([self::GROQ, self::GEMINI, self::OPENAI], $this->hosts());
    }

    public function testACutOffAnswerNeverEntersTheHistory(): void
    {
        $service = $this->service();
        $this->replies = [
            self::GROQ => [['PARTIAL answer', 'length'], ['Second answer.', 'stop']],
            self::GEMINI => [['GOOD answer.', 'STOP']],
        ];

        $first = $service->reply($this->restaurant(), 'es', 'EUR', null, 'First question');
        $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], 'Second question');

        $last = end($this->requests);
        self::assertSame(self::GROQ, $last['host']);
        self::assertSame(['First question', 'GOOD answer.', 'Second question'], $this->sentMessages($last));
        self::assertStringNotContainsString('PARTIAL', json_encode($last['body']));
    }

    public function testEveryProviderCutOffShowsTheFallbackAndKeepsNothing(): void
    {
        $service = $this->service();
        $this->replies = [
            self::GEMINI => [['Cut', 'MAX_TOKENS'], ['Now complete.', 'STOP']],
            self::GROQ => [['Cut', 'length']],
            self::OPENAI => [['Cut', 'length']],
        ];

        $first = $service->reply($this->restaurant(), 'es', 'EUR', null, 'First question');

        self::assertTrue($first['error']);
        self::assertNull($first['reply']);
        self::assertFalse($this->logs[0]->isSuccess());
        self::assertSame(AIFailureReason::CUT_OFF, $this->logs[0]->getFailureReason());
        self::assertSame(3, $this->logs[0]->getAttemptsCount());

        // Nothing of the failed exchange is remembered: the next question goes alone.
        $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], 'Second question');
        self::assertSame(['Second question'], $this->sentMessages(end($this->requests)));
    }

    public function testTheRouterStillReturnsACutOffAnswerWhenNotAskedToRejectIt(): void
    {
        // The translation callers rely on this (TranslationAiCaller decides
        // itself) — and, without tryFirst, on the usual tier order: Gemini first.
        $this->replies = [self::GEMINI => [['Cut', 'MAX_TOKENS']]];
        $router = new AIModelRouter($this->factory(), new NullLogger());

        $result = $router->route(new AIRequest('system', [new ChatMessage('user', 'hola')]), 'simple');

        self::assertTrue($result->isSuccess());
        self::assertSame('length', $result->response->finishReason);
        self::assertSame([self::GEMINI], $this->hosts());
    }
}
