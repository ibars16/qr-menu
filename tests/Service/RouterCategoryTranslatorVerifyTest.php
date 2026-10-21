<?php

namespace App\Tests\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\RouterCategoryTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * RouterCategoryTranslator::verifyAndFix() merges the check pass into the
 * first pass instead of replacing it — same contract as
 * RouterProductTranslatorVerifyTest, for category names.
 *
 * The real provider factory/router run against a MockHttpClient that
 * answers every request with the next scripted reply — no request can reach
 * a real API.
 */
final class RouterCategoryTranslatorVerifyTest extends TestCase
{
    private const SOURCE = [
        0 => 'Entrantes',
        1 => 'Pescados',
        2 => 'Postres',
    ];

    private const FIRST_PASS = [
        '0' => 'Vorspeisen',
        '1' => 'Fisch',
        '2' => 'Nachtisce',
    ];

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

    /** @param list<string> $replies model replies, in request order (first pass, verify pass) */
    private function translator(array $replies): RouterCategoryTranslator
    {
        $http = new MockHttpClient(function (string $method, string $url) use (&$replies): MockResponse {
            $this->requestedHosts[] = parse_url($url, PHP_URL_HOST);
            $content = array_shift($replies);
            if ($content === null) {
                return new MockResponse('{"error":{"message":"unexpected extra request"}}', ['http_code' => 500]);
            }

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => $content]]]]));
        });
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));

        return new RouterCategoryTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger());
    }

    public function testACheckThatReturnsOnlyOneOfThreeKeepsTheOtherTwo(): void
    {
        $result = $this->translator([
            json_encode(self::FIRST_PASS),
            json_encode(['2' => 'Nachtisch']),
        ])->translate(self::SOURCE, 'de');

        self::assertSame([0 => 'Vorspeisen', 1 => 'Fisch', 2 => 'Nachtisch'], $result);
        self::assertSame(['api.groq.com', 'api.groq.com'], $this->requestedHosts, 'two passes, both mocked');
    }

    public function testAnEmptyCheckKeepsTheFirstPass(): void
    {
        $result = $this->translator([
            json_encode(self::FIRST_PASS),
            '{}',
        ])->translate(self::SOURCE, 'de');

        self::assertSame([0 => 'Vorspeisen', 1 => 'Fisch', 2 => 'Nachtisce'], $result);
    }

    public function testACheckThatBlanksAnItemKeepsItsFirstPassTranslation(): void
    {
        $result = $this->translator([
            json_encode(self::FIRST_PASS),
            json_encode(['0' => '', '1' => '   ', '2' => 'Nachtisch']),
        ])->translate(self::SOURCE, 'de');

        self::assertSame([0 => 'Vorspeisen', 1 => 'Fisch', 2 => 'Nachtisch'], $result);
    }

    public function testACorrectedItemReplacesTheFirstPassAndAnUnknownIdIsIgnored(): void
    {
        $result = $this->translator([
            json_encode(self::FIRST_PASS),
            json_encode(['0' => 'Vorspeisen', '1' => 'Fischgerichte', '2' => 'Nachtisch', '7' => 'Erfunden']),
        ])->translate(self::SOURCE, 'de');

        self::assertSame([0 => 'Vorspeisen', 1 => 'Fischgerichte', 2 => 'Nachtisch'], $result);
    }
}
