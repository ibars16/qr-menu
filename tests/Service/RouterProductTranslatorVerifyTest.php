<?php

namespace App\Tests\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\RouterProductTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * RouterProductTranslator::verifyAndFix() merges the check pass into the
 * first pass instead of replacing it: an item the check omits or blanks
 * keeps its first-pass translation, a corrected one wins.
 *
 * The real provider factory/router run against a MockHttpClient that
 * answers every request with the next scripted reply — no request can reach
 * a real API. The first request is the translation pass, the second the
 * verify pass (groq-llama, the translator's first choice, with a fake key).
 */
final class RouterProductTranslatorVerifyTest extends TestCase
{
    private const SOURCE = [
        0 => ['name' => 'Croquetas', 'description' => 'De jamón'],
        1 => ['name' => 'Pulpo', 'description' => null],
        2 => ['name' => 'Tarta de queso', 'description' => null],
    ];

    private const FIRST_PASS = [
        '0' => ['name' => 'Kroketten', 'description' => 'Mit Schinken'],
        '1' => ['name' => 'Oktopus', 'description' => ''],
        '2' => ['name' => 'Käsekuchn', 'description' => ''],
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
    private function translator(array $replies): RouterProductTranslator
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

        return new RouterProductTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger());
    }

    public function testACheckThatReturnsOnlyOneOfThreeKeepsTheOtherTwo(): void
    {
        $result = $this->translator([
            json_encode(self::FIRST_PASS),
            json_encode(['2' => ['name' => 'Käsekuchen', 'description' => '']]),
        ])->translate(self::SOURCE, 'de');

        self::assertSame([
            0 => ['name' => 'Kroketten', 'description' => 'Mit Schinken'],
            1 => ['name' => 'Oktopus', 'description' => null],
            2 => ['name' => 'Käsekuchen', 'description' => null],
        ], $result);
        self::assertSame(['api.groq.com', 'api.groq.com'], $this->requestedHosts, 'two passes, both mocked');
    }

    public function testAnEmptyCheckKeepsTheFirstPass(): void
    {
        $result = $this->translator([
            json_encode(self::FIRST_PASS),
            '{}',
        ])->translate(self::SOURCE, 'de');

        self::assertSame([
            0 => ['name' => 'Kroketten', 'description' => 'Mit Schinken'],
            1 => ['name' => 'Oktopus', 'description' => null],
            2 => ['name' => 'Käsekuchn', 'description' => null],
        ], $result);
    }

    public function testACheckThatBlanksAnItemKeepsItsFirstPassTranslation(): void
    {
        $result = $this->translator([
            json_encode(self::FIRST_PASS),
            json_encode([
                '0' => ['name' => '', 'description' => ''],
                '1' => ['name' => '   ', 'description' => ''],
                '2' => ['name' => 'Käsekuchn', 'description' => ''],
            ]),
        ])->translate(self::SOURCE, 'de');

        self::assertSame('Kroketten', $result[0]['name']);
        self::assertSame('Mit Schinken', $result[0]['description']);
        self::assertSame('Oktopus', $result[1]['name']);
        self::assertCount(3, $result);
    }

    public function testACorrectedItemReplacesTheFirstPassAndAnUnknownIdIsIgnored(): void
    {
        $result = $this->translator([
            json_encode(self::FIRST_PASS),
            json_encode([
                '0' => ['name' => 'Kroketten', 'description' => 'Mit Serranoschinken'],
                '1' => ['name' => 'Oktopus', 'description' => ''],
                '2' => ['name' => 'Käsekuchen', 'description' => ''],
                '7' => ['name' => 'Erfunden', 'description' => ''],
            ]),
        ])->translate(self::SOURCE, 'de');

        self::assertSame([
            0 => ['name' => 'Kroketten', 'description' => 'Mit Serranoschinken'],
            1 => ['name' => 'Oktopus', 'description' => null],
            2 => ['name' => 'Käsekuchen', 'description' => null],
        ], $result);
    }
}
