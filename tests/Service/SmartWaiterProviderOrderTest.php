<?php

namespace App\Tests\Service;

use App\Service\AI\AIFailureReason;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who answers Smart Waiter first: config/ai_providers.yaml's
 * smart_waiter_first (Groq → Gemini flash-lite), then the rest in the usual
 * tier order — for simple and complex questions alike. Without that list
 * (translations, language detection) the router's order is exactly the
 * old one. Fake AI only (SmartWaiterFakeAiTrait): every unscripted
 * provider answers 500, so the hosts list is the order tried.
 */
final class SmartWaiterProviderOrderTest extends TestCase
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

    private function routeHosts(string $complexity, array $tryFirst): array
    {
        $this->requests = [];
        (new AIModelRouter($this->factory(), new NullLogger()))
            ->route(new AIRequest('system', [new ChatMessage('user', 'hola')]), $complexity, tryFirst: $tryFirst);

        return $this->hosts();
    }

    public function testTheRealConfigListsGroqThenGeminiFlashLite(): void
    {
        self::assertSame(['groq-llama', 'gemini-flash-lite'], $this->factory()->getSmartWaiterFirst());
    }

    public function testTryFirstGoesFirstForSimpleAndComplexAlikeThenTheRestOnce(): void
    {
        $first = ['groq-llama', 'gemini-flash-lite'];

        self::assertSame([self::GROQ, self::GEMINI, self::OPENAI], $this->routeHosts('simple', $first));
        self::assertSame([self::GROQ, self::GEMINI, self::OPENAI], $this->routeHosts('complex', $first), 'complex too; then the capable tier');
    }

    public function testWithoutTryFirstTheOrderIsTheOldOne(): void
    {
        // What the translation callers get: they never pass tryFirst.
        self::assertSame([self::GEMINI, self::GROQ, self::OPENAI], $this->routeHosts('simple', []));
        self::assertSame([self::OPENAI, self::GEMINI, self::GROQ], $this->routeHosts('complex', []));
    }

    public function testAnUnavailableIdInTryFirstIsSkipped(): void
    {
        // Unknown, manual_only (gemini-flash) and keyless (openrouter-free) are not in the list the router sees.
        self::assertSame(
            [self::OPENAI, self::GEMINI, self::GROQ],
            $this->routeHosts('simple', ['no-existe', 'gemini-flash', 'openrouter-free', 'openai-mini']),
        );
        self::assertSame(['gemini-flash-lite-latest'], array_values(array_unique(array_column(
            array_filter($this->requests, static fn (array $r) => $r['host'] === self::GEMINI),
            'model',
        ))), 'gemini-flash is never asked');
    }

    public function testTheChatAsksGroqFirst(): void
    {
        $this->replies = [self::GROQ => [['Tenemos ensalada.', 'stop']]];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué entrantes hay?');

        self::assertSame('Tenemos ensalada.', $result['reply']);
        self::assertSame([self::GROQ], $this->hosts());
        self::assertSame('groq-llama', $this->logs[0]->getProviderId());
        self::assertSame(1, $this->logs[0]->getAttemptsCount());
    }

    public function testGroqRateLimitedGeminiFlashLiteAnswers(): void
    {
        $this->replies = [
            self::GROQ => [[null, 429]],
            self::GEMINI => [['Tenemos ensalada.', 'STOP']],
        ];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué entrantes hay?');

        self::assertSame('Tenemos ensalada.', $result['reply']);
        self::assertSame([self::GROQ, self::GEMINI], $this->hosts());
        self::assertSame('gemini-flash-lite', $this->logs[0]->getProviderId());
        self::assertSame(2, $this->logs[0]->getAttemptsCount());
    }

    public function testWithoutAGroqKeyGeminiFlashLiteGoesFirst(): void
    {
        $_ENV['GROQ_API_KEY'] = ''; // restored by fakeAiTearDown()
        $this->replies = [self::GEMINI => [['Tenemos ensalada.', 'STOP']]];

        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué entrantes hay?');

        self::assertSame('Tenemos ensalada.', $result['reply']);
        self::assertSame([self::GEMINI], $this->hosts());
        self::assertSame(1, $this->logs[0]->getAttemptsCount());
    }

    public function testEveryProviderFailingStillEndsInTheFallback(): void
    {
        $result = $this->service()->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué entrantes hay?');

        self::assertTrue($result['error']);
        self::assertSame([self::GROQ, self::GEMINI, self::OPENAI], $this->hosts());
        self::assertNotSame(AIFailureReason::CUT_OFF, $this->logs[0]->getFailureReason());
    }
}
