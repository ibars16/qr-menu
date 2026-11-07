<?php

namespace App\Tests\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\RouterCategoryTranslator;
use App\Service\RouterLabelTranslator;
use App\Service\RouterProductTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An answer the provider cut off at max tokens (finish_reason "length") is
 * never used — even when what came back happens to be valid JSON — and isn't
 * sent again to another provider. A cut-off check pass keeps the first pass.
 *
 * The real provider factory/router run against a MockHttpClient that answers
 * every request with the next scripted reply (groq-llama, the translators'
 * first choice, with a fake key) — no request can reach a real API.
 */
final class TranslationCutOffTest extends TestCase
{
    private mixed $previousGroqKey;

    /** @var list<string> hosts actually requested */
    private array $requestedHosts = [];

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

    /** @param list<array{0: string, 1: string}> $replies [content, finish_reason], in request order */
    private function factory(array $replies): AIProviderFactory
    {
        $http = new MockHttpClient(function (string $method, string $url) use (&$replies): MockResponse {
            $this->requestedHosts[] = parse_url($url, PHP_URL_HOST);
            $reply = array_shift($replies);
            if ($reply === null) {
                return new MockResponse('{"error":{"message":"unexpected extra request"}}', ['http_code' => 500]);
            }

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => $reply[0]], 'finish_reason' => $reply[1]]]]));
        });

        return new AIProviderFactory($http, dirname(__DIR__, 2));
    }

    private function dishTranslator(array $replies): RouterProductTranslator
    {
        $factory = $this->factory($replies);

        return new RouterProductTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger());
    }

    private function categoryTranslator(array $replies): RouterCategoryTranslator
    {
        $factory = $this->factory($replies);

        return new RouterCategoryTranslator(new RouterLabelTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger()));
    }

    public function testACutOffDishTranslationIsNotUsedEvenIfItParses(): void
    {
        $result = $this->dishTranslator([
            [json_encode(['0' => ['name' => 'Kroketten', 'description' => '']]), 'length'],
        ])->translate([0 => ['name' => 'Croquetas', 'description' => null]], 'de');

        self::assertSame([], $result);
        self::assertSame(['api.groq.com'], $this->requestedHosts, 'not sent again, not checked');
    }

    public function testACutOffCheckPassKeepsTheFirstPass(): void
    {
        $result = $this->dishTranslator([
            [json_encode(['0' => ['name' => 'Kroketten', 'description' => '']]), 'stop'],
            [json_encode(['0' => ['name' => 'Krokett', 'description' => '']]), 'length'],
        ])->translate([0 => ['name' => 'Croquetas', 'description' => null]], 'de');

        self::assertSame([0 => ['name' => 'Kroketten', 'description' => null]], $result);
    }

    public function testACutOffCategoryTranslationIsNotUsed(): void
    {
        $result = $this->categoryTranslator([
            [json_encode(['3' => 'Nachspeisen']), 'length'],
        ])->translate([3 => 'Postres'], 'de');

        self::assertSame([], $result);
        self::assertSame(['api.groq.com'], $this->requestedHosts);
    }

    public function testAnAnswerThatEndedNormallyIsUsed(): void
    {
        $result = $this->categoryTranslator([
            [json_encode(['3' => 'Nachspeisen']), 'stop'],
            [json_encode(['3' => 'Nachspeisen']), 'stop'],
        ])->translate([3 => 'Postres'], 'de');

        self::assertSame([3 => 'Nachspeisen'], $result);
    }
}
