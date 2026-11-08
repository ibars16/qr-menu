<?php

namespace App\Tests\Command;

use App\Command\TranslationEvalCommand;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\RouterProductTranslator;
use App\Service\TranslationGuidelines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * app:translation:eval with a FAKE AI: the real provider factory, router
 * and RouterProductTranslator run against a MockHttpClient (fake keys) —
 * no request can reach a real API. By default the fake translates every
 * name to "<locale>:<name>" and its review returns "{}" (no changes);
 * $this->script overrides the reply of a given call (1-based).
 *
 * The CSV and the result folder live in a temporary directory.
 */
final class TranslationEvalCommandTest extends TestCase
{
    private const ENV_KEYS = ['GROQ_API_KEY', 'GEMINI_API_KEY', 'OPENAI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY', 'OPENROUTER_API_KEY'];

    /** @var array<string, mixed> */
    private array $previousEnv = [];
    private mixed $previousServerGroq = null;
    private string|false $previousGetenvGroq = false;
    private string $dir;
    private MockClock $clock;

    /** @var list<array{host: string, system: string, user: string, body: string}> */
    private array $requests = [];

    /** @var array<int, array{0: int, 1: string, 2?: string}> call number → [http code, content, finish_reason] */
    private array $script = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
            // Every provider "available": a fallback would have somewhere to go — it must never happen.
            $_ENV[$key] = 'test-key-never-sent';
        }
        $this->previousServerGroq = $_SERVER['GROQ_API_KEY'] ?? null;
        $this->previousGetenvGroq = getenv('GROQ_API_KEY');
        $this->dir   = sys_get_temp_dir() . '/translation-eval-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->clock = new MockClock('2026-11-08 10:00:00');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        if ($this->previousServerGroq !== null) {
            $_SERVER['GROQ_API_KEY'] = $this->previousServerGroq;
        }
        if ($this->previousGetenvGroq !== false) {
            putenv('GROQ_API_KEY=' . $this->previousGetenvGroq);
        }
        foreach (glob($this->dir . '/{,out/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->dir . '/out');
        @rmdir($this->dir);
    }

    // --- helpers -------------------------------------------------------

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'], true);
            $this->requests[] = [
                'host'   => parse_url($url, PHP_URL_HOST),
                'system' => $body['messages'][0]['content'],
                'user'   => $body['messages'][1]['content'],
                'body'   => $options['body'],
            ];
            $n = count($this->requests);

            if (isset($this->script[$n])) {
                [$code, $content] = $this->script[$n];
                $finish = $this->script[$n][2] ?? 'stop';

                return $code === 200
                    ? new MockResponse(json_encode(['choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]]]))
                    : new MockResponse($content, ['http_code' => $code]);
            }

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => $this->defaultReply($body)], 'finish_reason' => 'stop']]]));
        });
    }

    /** Translation: "<locale>:<name>" for every dish. Review: "{}" (nothing changed). */
    private function defaultReply(array $body): string
    {
        $system = $body['messages'][0]['content'];
        if (str_starts_with($system, 'You are a translation quality checker')) {
            return '{}';
        }
        preg_match('/ISO 639-1 code "([a-z]{2})"\. /', $system, $m);
        $items = json_decode(substr($body['messages'][1]['content'], strlen('Dishes: ')), true);
        $out   = [];
        foreach ($items as $item) {
            $out[$item['id']] = ['name' => $m[1] . ':' . $item['name'], 'description' => $item['description'] === '' ? '' : $m[1] . ':' . $item['description']];
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    private function translator(MockHttpClient $http): RouterProductTranslator
    {
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));

        return new RouterProductTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger());
    }

    private function tester(): CommandTester
    {
        $http    = $this->http();
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));

        return new CommandTester(new TranslationEvalCommand($factory, $this->translator($http), $this->clock, dirname(__DIR__, 2)));
    }

    /** @param list<array{0: string, 1: string, 2?: string, 3?: string, 4?: string}> $dishes [seccion, nombre, descripcion, trampa, significado], all in tramo 1 */
    private function csv(array $dishes): string
    {
        $path = $this->dir . '/platos.csv';
        $h    = fopen($path, 'w');
        fputcsv($h, ['id', 'tramo', 'seccion', 'nombre', 'descripcion', 'trampa', 'significado'], escape: '');
        foreach ($dishes as $i => $d) {
            fputcsv($h, [$i + 1, 1, $d[0], $d[1], $d[2] ?? '', $d[3] ?? '', $d[4] ?? ''], escape: '');
        }
        fputcsv($h, [999, 2, 'Postres', 'Plato de otro tramo', '', '', ''], escape: '');
        fclose($h);

        return $path;
    }

    /** @return list<array{0: string, 1: string}> */
    private function manyDishes(int $n): array
    {
        return array_map(static fn (int $i) => ['Entrantes', "Plato {$i}"], range(1, $n));
    }

    private function runEval(string $csv, array $extra = []): CommandTester
    {
        $tester = $this->tester();
        $tester->execute($extra + ['--tramo' => '1', '--proveedor' => 'groq', '--pausa' => '0', '--csv' => $csv, '--salida' => $this->dir . '/out']);

        return $tester;
    }

    private function resultFile(): array
    {
        return json_decode(file_get_contents($this->dir . '/out/tramo-1.groq.json'), true);
    }

    /** @return list<array{id: string, name: string, description: string, source_locale?: string, menu_section?: string}> */
    private function items(int $request): array
    {
        return json_decode(substr($this->requests[$request]['user'], strlen('Dishes: ')), true);
    }

    private function isReview(int $request): bool
    {
        return str_starts_with($this->requests[$request]['system'], 'You are a translation quality checker');
    }

    // --- 1. the menu's very prompts ------------------------------------

    public function testItSendsExactlyWhatTheMenuTranslatorSends(): void
    {
        $csv = $this->csv([['Entrantes', 'Tortilla de camarones', 'Tortitas de garbanzo.']]);
        $this->runEval($csv);
        $evalFirst  = $this->requests[0];
        $evalReview = $this->requests[1];

        // The menu's translate() on the same dish, same section, same source locale.
        $this->requests = [];
        $this->translator($this->http())->translate(
            [1 => ['name' => 'Tortilla de camarones', 'description' => 'Tortitas de garbanzo.', 'section' => 'Entrantes']],
            'en',
            [1 => 'es'],
        );

        self::assertSame($this->requests[0]['body'], $evalFirst['body'], 'translation request, byte for byte');
        self::assertSame($this->requests[1]['body'], $evalReview['body'], 'review request, byte for byte');
        self::assertStringContainsString(TranslationGuidelines::DISH, $evalFirst['system']);
        self::assertStringContainsString(TranslationGuidelines::DISH_REVIEW, $evalReview['system']);
    }

    // --- 2. what goes to the AI ----------------------------------------

    public function testReviewerNotesAreNeverSentAndTheSectionIsMenuSection(): void
    {
        $csv = $this->csv([['Carnes', 'Pies de cerdo', 'Guisados.', 'NOTA_TRAMPA_SECRETA', 'NOTA_SIGNIFICADO_SECRETO']]);
        $this->runEval($csv);

        self::assertCount(8, $this->requests);
        foreach ($this->requests as $r) {
            self::assertStringNotContainsString('NOTA_TRAMPA_SECRETA', $r['body']);
            self::assertStringNotContainsString('NOTA_SIGNIFICADO_SECRETO', $r['body']);
        }
        self::assertSame(
            [['id' => '1', 'name' => 'Pies de cerdo', 'description' => 'Guisados.', 'source_locale' => 'es', 'menu_section' => 'Carnes']],
            $this->items(0),
        );
        self::assertSame('Carnes', $this->items(1)[0]['menu_section']);
        self::assertSame('es', $this->items(1)[0]['source_locale']);

        // …but they are in the result file, for the reviewer.
        self::assertSame('NOTA_TRAMPA_SECRETA', $this->resultFile()['platos']['1']['trampa']);
    }

    // --- 3. batches and call count -------------------------------------

    public function testThirtyDishesAreTwoBatchesOf25And5PerLanguage(): void
    {
        $tester = $this->runEval($this->csv($this->manyDishes(30)));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(16, $this->requests, '2 batches × 4 languages × (translate + review)');

        $i = 0;
        foreach (TranslationEvalCommand::LOCALES as $locale) {
            foreach ([25, 5] as $size) {
                foreach ([false, true] as $review) {
                    self::assertSame($review, $this->isReview($i), "call {$i}");
                    self::assertStringContainsString("ISO 639-1 code \"{$locale}\"", $this->requests[$i]['system']);
                    self::assertCount($size, $this->items($i));
                    $i++;
                }
            }
        }
        self::assertNotContains('999', array_merge(...array_map(fn ($n) => array_column($this->items($n), 'id'), range(0, 15))), 'another tramo is never sent');
        self::assertSame(['en:1', 'en:2', 'fr:1', 'fr:2', 'de:1', 'de:2', 'it:1', 'it:2'], array_keys($this->resultFile()['tandas']));
    }

    // --- 4. no fallback ------------------------------------------------

    public function testAnExhaustedQuotaStopsTheRunAndNeverTriesAnotherProvider(): void
    {
        $this->script[3] = [429, '{"error":{"message":"quota"}}'];
        $tester = $this->runEval($this->csv($this->manyDishes(30)));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('rate_limited', $tester->getDisplay());
        self::assertCount(3, $this->requests, 'stops at the failed call');
        self::assertSame(['api.groq.com'], array_values(array_unique(array_column($this->requests, 'host'))));

        $result = $this->resultFile();
        self::assertSame(['en:1' => 'revisada'], $result['tandas'], 'what was done is kept; en:2 stays pending');
        self::assertSame('en:Plato 1', $result['platos']['1']['en']['revisada']['name']);
    }

    // --- 5. an unusable review stops, never "no changes" ----------------

    /** @return iterable<string, array{0: array{0: int, 1: string, 2?: string}}> */
    public static function unusableReviews(): iterable
    {
        yield 'not JSON'  => [[200, 'Sorry, I cannot help with that.']];
        yield 'cut off'   => [[200, '{"1": {"name": "x", "description": ""}}', 'length']];
        yield 'HTTP 500'  => [[500, '{"error":{"message":"boom"}}']];
    }

    #[DataProvider('unusableReviews')]
    public function testAnUnusableReviewStopsAndIsNotRecordedAsUnchanged(array $reply): void
    {
        $this->script[2] = $reply;
        $tester = $this->runEval($this->csv([['Entrantes', 'Croquetas']]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Parado', $tester->getDisplay());
        self::assertCount(2, $this->requests);

        $entry = $this->resultFile()['platos']['1']['en'];
        self::assertSame(['en:1' => 'traducida'], $this->resultFile()['tandas']);
        self::assertSame('en:Croquetas', $entry['primera']['name']);
        self::assertNull($entry['revisada']);
        self::assertNull($entry['revision_cambio']);
    }

    // --- 6. resume -----------------------------------------------------

    public function testAResumedRunDoesOnlyTheCallsNotDoneYet(): void
    {
        $csv = $this->csv([['Entrantes', 'Croquetas']]);
        $this->script[2] = [429, '{}'];
        $this->runEval($csv);
        self::assertCount(2, $this->requests);
        self::assertSame(['en:1' => 'traducida'], $this->resultFile()['tandas']);

        $this->requests = [];
        $this->script   = [];
        $tester = $this->runEval($csv);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(7, $this->requests, '8 calls in all, the first translation not repeated');
        self::assertTrue($this->isReview(0), 'it picks up with the pending review');
        self::assertSame(
            [['id' => '1', 'source_name' => 'Croquetas', 'source_description' => '', 'translated_name' => 'en:Croquetas', 'translated_description' => '', 'source_locale' => 'es', 'menu_section' => 'Entrantes']],
            $this->items(0),
            'the review gets the saved first pass',
        );

        // A third run has nothing left to do.
        $this->requests = [];
        $this->runEval($csv);
        self::assertCount(0, $this->requests);
    }

    // --- 7. changed prompts or CSV refuse to resume ---------------------

    public function testChangedPromptsRefuseToResume(): void
    {
        $csv = $this->csv([['Entrantes', 'Croquetas']]);
        $this->script[2] = [429, '{}'];
        $this->runEval($csv);

        $file = $this->dir . '/out/tramo-1.groq.json';
        $data = $this->resultFile();
        self::assertArrayHasKey('traducir', $data['prompts_sha256']);
        $data['prompts_sha256']['revisar'] = str_repeat('0', 64); // as if the review prompt had changed since
        file_put_contents($file, json_encode($data));

        $this->requests = [];
        $this->script   = [];
        $tester = $this->runEval($csv);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('han cambiado', $tester->getDisplay());
        self::assertCount(0, $this->requests, 'stopped before sending anything');
    }

    public function testAChangedCsvRefusesToResume(): void
    {
        $csv = $this->csv([['Entrantes', 'Croquetas']]);
        $this->script[2] = [429, '{}'];
        $this->runEval($csv);

        file_put_contents($csv, "\n", FILE_APPEND);
        $this->requests = [];
        $tester = $this->runEval($csv);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('El CSV ha cambiado', $tester->getDisplay());
        self::assertCount(0, $this->requests);
    }

    // --- 8. what the review changed ------------------------------------

    public function testRevisionChangedFlagAndADishTheReviewOmitsKeepsItsFirstPass(): void
    {
        $this->script[2] = [200, json_encode(['2' => ['name' => 'Pig\'s trotters', 'description' => '']])];
        $this->runEval($this->csv([['Entrantes', 'Croquetas'], ['Carnes', 'Pies de cerdo']]));

        $platos = $this->resultFile()['platos'];
        self::assertSame($platos['1']['en']['primera'], $platos['1']['en']['revisada']);
        self::assertFalse($platos['1']['en']['revision_cambio']);
        self::assertSame('en:Pies de cerdo', $platos['2']['en']['primera']['name']);
        self::assertSame('Pig\'s trotters', $platos['2']['en']['revisada']['name']);
        self::assertTrue($platos['2']['en']['revision_cambio']);
        self::assertSame(['provider' => 'groq-llama', 'model' => 'openai/gpt-oss-120b'], $platos['2']['en']['traducido_por']);
        self::assertSame(['provider' => 'groq-llama', 'model' => 'openai/gpt-oss-120b'], $platos['2']['en']['revisado_por']);
        self::assertFalse($platos['1']['fr']['revision_cambio'], 'default review "{}": nothing changed');
    }

    // --- 9. provider -------------------------------------------------------

    public function testAProviderWithNoKeyOrAnUnknownOneFailsBeforeAnyRequest(): void
    {
        $csv = $this->csv([['Entrantes', 'Croquetas']]);

        unset($_ENV['GROQ_API_KEY'], $_SERVER['GROQ_API_KEY']);
        putenv('GROQ_API_KEY');
        $tester = $this->runEval($csv);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no está disponible', $tester->getDisplay());

        $tester = $this->runEval($csv, ['--proveedor' => 'claude']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());

        self::assertCount(0, $this->requests);
        self::assertFileDoesNotExist($this->dir . '/out/tramo-1.groq.json');
    }

    // --- 10. pause -----------------------------------------------------

    public function testItPausesBetweenCalls(): void
    {
        $csv   = $this->csv([['Entrantes', 'Croquetas']]);
        $start = $this->clock->now();
        $this->runEval($csv, ['--pausa' => '5']);

        self::assertCount(8, $this->requests);
        self::assertSame(35, $this->clock->now()->getTimestamp() - $start->getTimestamp(), '7 pauses of 5 s between 8 calls');
    }

    // --- 11. the menu's translate() is still firstPass + review ----------

    public function testTheMenuTranslateIsFirstPassThenReview(): void
    {
        $this->script[2] = [200, json_encode(['0' => ['name' => 'Croquettes', 'description' => '']])];
        $result = $this->translator($this->http())->translate([0 => ['name' => 'Croquetas', 'description' => null]], 'en', [0 => 'es']);

        self::assertSame([0 => ['name' => 'Croquettes', 'description' => null]], $result);
        self::assertCount(2, $this->requests);
        self::assertSame(['api.groq.com', 'api.groq.com'], array_column($this->requests, 'host'));
    }
}
