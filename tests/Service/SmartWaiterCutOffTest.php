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

    public function testGeminiCutOffMovesOnToGroq(): void
    {
        $this->replies = [
            self::GEMINI => [['Sin gluten tienes la ensalada y', 'MAX_TOKENS']],
            self::GROQ => [['Sin gluten tienes la ensalada.', 'stop']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué hay sin gluten?');

        self::assertFalse($result['error']);
        self::assertSame('Sin gluten tienes la ensalada.', $result['reply']);
        self::assertSame([self::GEMINI, self::GROQ], $this->hosts());
        self::assertSame('groq-llama', $this->logs[0]->getProviderId());
        self::assertSame(2, $this->logs[0]->getAttemptsCount());
    }

    public function testGroqCutOffMovesOnToTheNextProvider(): void
    {
        $this->replies = [
            self::GEMINI => [['', 'STOP']], // no usable text: an ordinary failure
            self::GROQ => [['Tenemos tres postres: tarta', 'length']],
            self::OPENAI => [['Tenemos tres postres: tarta, flan y helado.', 'stop']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué postres hay?');

        self::assertSame('Tenemos tres postres: tarta, flan y helado.', $result['reply']);
        self::assertSame([self::GEMINI, self::GROQ, self::OPENAI], $this->hosts());
        self::assertSame('openai-mini', $this->logs[0]->getProviderId());
    }

    public function testOpenAiCutOffMovesOnToTheNextProvider(): void
    {
        $this->replies = [
            self::OPENAI => [['Para cuatro os sugiero', 'length']],
            self::GEMINI => [['Para cuatro os sugiero las verduras a la plancha.', 'STOP']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, self::COMPLEX_MESSAGE);

        self::assertSame('Para cuatro os sugiero las verduras a la plancha.', $result['reply']);
        self::assertSame([self::OPENAI, self::GEMINI], $this->hosts(), 'complex: OpenAI first, then the fast tier');
        self::assertSame('complex', $this->logs[0]->getComplexity());
    }

    public function testGeminiStoppedForAnyOtherReasonIsNotUsedEither(): void
    {
        $this->replies = [
            self::GEMINI => [['La pizza lleva', 'SAFETY']],
            self::GROQ => [['La pizza lleva tomate y mozzarella.', 'stop']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué lleva la pizza?');

        self::assertSame('La pizza lleva tomate y mozzarella.', $result['reply']);
    }

    public function testACutOffAnswerNeverEntersTheHistory(): void
    {
        $service = $this->service();
        $this->replies = [
            self::GEMINI => [['PARTIAL answer', 'MAX_TOKENS'], ['Second answer.', 'STOP']],
            self::GROQ => [['GOOD answer.', 'stop']],
        ];

        $first = $service->reply($this->restaurant(), 'es', 'EUR', null, 'First question');
        $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], 'Second question');

        $last = end($this->requests);
        self::assertSame(self::GEMINI, $last['host']);
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
        // The translation callers rely on this (TranslationAiCaller decides itself).
        $this->replies = [self::GEMINI => [['Cut', 'MAX_TOKENS']]];
        $router = new AIModelRouter($this->factory(), new NullLogger());

        $result = $router->route(new AIRequest('system', [new ChatMessage('user', 'hola')]), 'simple');

        self::assertTrue($result->isSuccess());
        self::assertSame('length', $result->response->finishReason);
        self::assertSame([self::GEMINI], $this->hosts());
    }
}
