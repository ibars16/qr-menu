<?php

namespace App\Tests\Service\AI;

use App\Service\AI\AIProviderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * manual_only in the real config/ai_providers.yaml: gemini-flash exists
 * only for manual model comparison (app:smart-waiter:ask --modelos) — the
 * customer chat and translations (getAvailableProviders()) and menu photos
 * (getAvailableVisionProviders()) never get it; only asking by its id does.
 * No request is ever made (the MockHttpClient has no responses).
 */
final class ManualOnlyProviderTest extends TestCase
{
    private ?string $previousKey = null;

    protected function setUp(): void
    {
        $this->previousKey = $_ENV['GEMINI_API_KEY'] ?? null;
        $_ENV['GEMINI_API_KEY'] = 'test-key-never-sent';
    }

    protected function tearDown(): void
    {
        if ($this->previousKey === null) {
            unset($_ENV['GEMINI_API_KEY']);
        } else {
            $_ENV['GEMINI_API_KEY'] = $this->previousKey;
        }
    }

    private function factory(): AIProviderFactory
    {
        return new AIProviderFactory(new MockHttpClient([]), dirname(__DIR__, 3));
    }

    public function testTheChatTranslationsAndMenuPhotosNeverGetAManualOnlyProvider(): void
    {
        $factory = $this->factory();

        $chat = $factory->getAvailableProviders();
        $ids = array_map(static fn ($p) => $p->getId(), [...$chat['fast'], ...$chat['capable']]);
        self::assertContains('gemini-flash-lite', $ids, 'same key, normal entry: still there');
        self::assertNotContains('gemini-flash', $ids);

        $vision = array_map(static fn ($p) => $p->getId(), $factory->getAvailableVisionProviders());
        self::assertNotContains('gemini-flash', $vision);
    }

    public function testOnlyAskingByItsIdReachesIt(): void
    {
        $provider = $this->factory()->getProviderById('gemini-flash');

        self::assertNotNull($provider);
        self::assertSame('gemini-flash-latest', $provider->getModel());
    }
}
