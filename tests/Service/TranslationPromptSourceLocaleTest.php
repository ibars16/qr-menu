<?php

namespace App\Tests\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\RouterCategoryTranslator;
use App\Service\RouterLabelTranslator;
use App\Service\RouterProductTranslator;
use App\Service\RouterTagTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The dish, category and tag translators never assume the source text is
 * Spanish: neither pass's prompt names a language other than the target,
 * and every item sent carries its own "source_locale".
 *
 * Same MockHttpClient set-up as RouterProductTranslatorVerifyTest — no
 * request can reach a real API; this records each request's messages.
 */
final class TranslationPromptSourceLocaleTest extends TestCase
{
    private mixed $previousGroqKey;

    /** @var list<array{system: string, user: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->previousGroqKey = $_ENV['GROQ_API_KEY'] ?? null;
        $_ENV['GROQ_API_KEY'] = 'test-key-never-sent';
    }

    protected function tearDown(): void
    {
        if ($this->previousGroqKey === null) {
            unset($_ENV['GROQ_API_KEY']);
        } else {
            $_ENV['GROQ_API_KEY'] = $this->previousGroqKey;
        }
    }

    /** @param list<string> $replies */
    private function factory(array $replies): AIProviderFactory
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$replies): MockResponse {
            $messages = json_decode($options['body'], true)['messages'] ?? [];
            $this->requests[] = [
                'system' => $messages[0]['content'] ?? '',
                'user'   => $messages[1]['content'] ?? '',
            ];

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => array_shift($replies) ?? '{}']]]]));
        });

        return new AIProviderFactory($http, dirname(__DIR__, 2));
    }

    private function labels(array $replies): RouterLabelTranslator
    {
        $factory = $this->factory($replies);

        return new RouterLabelTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger());
    }

    /** @return list<array<string, mixed>> the JSON items after "<Label>: " in a user message */
    private function items(string $user): array
    {
        return json_decode(substr($user, strpos($user, '[')), true);
    }

    private function assertNoAssumedLanguage(): void
    {
        self::assertCount(2, $this->requests, 'translate + check');
        foreach ($this->requests as $request) {
            self::assertDoesNotMatchRegularExpression('/spanish|español|castellano/i', $request['system']);
            self::assertStringContainsString('source_locale', $request['system']);
        }
    }

    public function testDishesAndOwnerTextsCarryEachItemsOwnSourceLanguage(): void
    {
        $factory = $this->factory([
            json_encode(['0' => ['name' => 'Zwiebelsuppe', 'description' => ''], '1' => ['name' => 'Matcha-Pulver', 'description' => '']]),
            '{}',
        ]);

        (new RouterProductTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger()))
            ->translate([0 => ['name' => 'Soupe à l\'oignon', 'description' => null], 1 => ['name' => 'Matcha powder', 'description' => null]], 'de', [0 => 'fr', 1 => 'en']);

        $this->assertNoAssumedLanguage();
        self::assertSame(['fr', 'en'], array_column($this->items($this->requests[0]['user']), 'source_locale'));
        self::assertSame(['fr', 'en'], array_column($this->items($this->requests[1]['user']), 'source_locale'));
    }

    public function testCategoriesCarryTheirSourceLanguage(): void
    {
        (new RouterCategoryTranslator($this->labels([json_encode(['7' => 'Vorspeisen']), '{}'])))
            ->translate([7 => 'Entrées'], 'de', [7 => 'fr']);

        $this->assertNoAssumedLanguage();
        self::assertSame(['fr'], array_column($this->items($this->requests[0]['user']), 'source_locale'));
        self::assertSame(['fr'], array_column($this->items($this->requests[1]['user']), 'source_locale'));
    }

    public function testTagsCarryTheirSourceLanguage(): void
    {
        (new RouterTagTranslator($this->labels([json_encode(['3' => 'Hausgemacht']), '{}'])))
            ->translate([3 => 'Fatto in casa'], 'de', [3 => 'it']);

        $this->assertNoAssumedLanguage();
        self::assertSame(['it'], array_column($this->items($this->requests[0]['user']), 'source_locale'));
        self::assertSame(['it'], array_column($this->items($this->requests[1]['user']), 'source_locale'));
    }
}
