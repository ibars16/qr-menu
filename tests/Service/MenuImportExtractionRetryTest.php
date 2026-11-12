<?php

namespace App\Tests\Service;

use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Restaurant;
use App\Enum\MenuImportPageStatus;
use App\Service\AI\AIProviderFactory;
use App\Service\MenuImportExtractionService;
use App\Service\MenuVisionPromptBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Retries of a menu photo when Google is saturated (503 "high demand"),
 * with a FAKE AI (MockHttpClient: no request ever leaves) and a FAKE clock
 * (MockClock: the 3 s / 8 s waits are recorded, never slept).
 */
final class MenuImportExtractionRetryTest extends TestCase
{
    private const OVERLOADED = 'This model is currently experiencing high demand. Spikes in demand are usually temporary. Please try again later.';

    private ?string $previousKey = null;
    private string $uploadsDir;
    private MockClock $clock;
    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->previousKey = $_ENV['GEMINI_API_KEY'] ?? null;
        $_ENV['GEMINI_API_KEY'] = 'test-key-never-sent';

        $this->uploadsDir = sys_get_temp_dir() . '/menu-import-retry-' . bin2hex(random_bytes(4));
        mkdir($this->uploadsDir . '/menu-imports', 0777, true);
        file_put_contents($this->uploadsDir . '/menu-imports/page.jpg', "\xFF\xD8\xFF\xE0fake-jpeg");

        $this->clock = new MockClock('2026-11-13 12:00:00');
    }

    protected function tearDown(): void
    {
        if ($this->previousKey === null) {
            unset($_ENV['GEMINI_API_KEY']);
        } else {
            $_ENV['GEMINI_API_KEY'] = $this->previousKey;
        }
        @unlink($this->uploadsDir . '/menu-imports/page.jpg');
        @rmdir($this->uploadsDir . '/menu-imports');
        @rmdir($this->uploadsDir);
    }

    public function test503ThenOkReadsThePage(): void
    {
        $page = $this->analyze([$this->overloaded(), $this->ok()]);

        self::assertSame(MenuImportPageStatus::EXTRACTED, $page->getStatus());
        self::assertNull($page->getErrorMessage());
        self::assertSame(['categories' => []], $page->getExtractedData());
        self::assertCount(2, $this->requests);
        self::assertSame('2026-11-13 12:00:03', $this->clock->now()->format('Y-m-d H:i:s'), 'waited 3 s before the 2nd attempt');
    }

    public function testThree503sGiveUpWithTheSaturatedMessage(): void
    {
        $page = $this->analyze([$this->overloaded(), $this->overloaded(), $this->overloaded(), $this->ok()]);

        self::assertSame(MenuImportPageStatus::FAILED, $page->getStatus());
        self::assertCount(3, $this->requests, '3 attempts at most, never a 4th');
        self::assertSame('2026-11-13 12:00:11', $this->clock->now()->format('Y-m-d H:i:s'), 'waited 3 s + 8 s');
        self::assertTrue($page->isTemporaryFailure());
        self::assertStringStartsWith(MenuImportExtractionService::TEMPORARY_FAILURE_PREFIX . 'Gemini vision error: ', (string) $page->getErrorMessage());
    }

    public function testMalformedAnswerIsNotRetriedAndKeepsTheUsualMessage(): void
    {
        $page = $this->analyze([
            new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => 'not json at all']]]]]])),
            $this->ok(),
        ]);

        self::assertSame(MenuImportPageStatus::FAILED, $page->getStatus());
        self::assertCount(1, $this->requests, 'no retry');
        self::assertSame('2026-11-13 12:00:00', $this->clock->now()->format('Y-m-d H:i:s'), 'no wait');
        self::assertFalse($page->isTemporaryFailure(), 'the usual "check your photos" message');
    }

    public function testTheSaturatedMessageExistsInTheSixAdminLanguages(): void
    {
        $expected = [
            'es' => 'El servicio de lectura de menús está saturado en este momento. Tu foto está bien: inténtalo de nuevo en unos minutos.',
        ];
        foreach (['es', 'en', 'fr', 'de', 'it', 'pt'] as $locale) {
            $yaml = Yaml::parseFile(dirname(__DIR__, 2) . "/translations/admin_menu_import.$locale.yaml");
            $text = $yaml['complete']['subtitle_failed_temporary'] ?? null;
            self::assertIsString($text, $locale);
            self::assertNotSame($yaml['complete']['subtitle_failed'], $text, $locale);
            if (isset($expected[$locale])) {
                self::assertSame($expected[$locale], $text);
            }
        }
    }

    /** @param list<MockResponse> $responses */
    private function analyze(array $responses): MenuImportPage
    {
        $http = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requests[] = $url;

            return array_shift($responses) ?? throw new \LogicException('unexpected extra AI call');
        });

        $service = new MenuImportExtractionService(
            new AIProviderFactory($http, dirname(__DIR__, 2)),
            new MenuVisionPromptBuilder(),
            $this->createStub(EntityManagerInterface::class),
            dirname(__DIR__, 2),
            $this->uploadsDir,
            $this->clock,
        );

        $page = new MenuImportPage(new MenuImportBatch(new Restaurant()), 'uploads/menu-imports/page.jpg', 0, 'hash');
        $service->analyzePage($page);

        return $page;
    }

    private function overloaded(): MockResponse
    {
        return new MockResponse(json_encode(['error' => ['code' => 503, 'message' => self::OVERLOADED, 'status' => 'UNAVAILABLE']]), ['http_code' => 503]);
    }

    private function ok(): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => '{"categories": []}']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]));
    }
}
