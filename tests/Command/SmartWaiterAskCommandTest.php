<?php

namespace App\Tests\Command;

use App\Command\SmartWaiterAskCommand;
use App\Service\SmartWaiterModelComparison;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * app:smart-waiter:ask with FAKE HTTP: no chat endpoint and no AI is ever
 * reached — the MockHttpClient plays POST /r/{slug}/chat, the MockClock
 * makes every pause instant. Reading the CSV, reusing each conversation's
 * id, the browser's request body, the Excel-friendly output, errors, the
 * localhost-only guard, and the "modelo" column — read from a stubbed
 * Connection standing in for smart_waiter_exchange_log (no DB either).
 */
final class SmartWaiterAskCommandTest extends TestCase
{
    private string $dir;

    /** @var list<array{url: string, body: array, headers: array}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    private MockClock $clock;

    /** @var list<array<string, mixed>|false> exchange-log rows the stubbed Connection hands back, in order */
    private array $logRows = [];

    /** @var list<array{sql: string, params: array}> */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sw-ask-test-' . uniqid();
        mkdir($this->dir);
        $this->clock = new MockClock('2026-11-08 12:00:00');
    }

    protected function tearDown(): void
    {
        $default = $this->dir . '/' . SmartWaiterAskCommand::DEFAULT_OUT;
        if (is_file($default)) {
            unlink($default);
            rmdir(dirname($default));
            rmdir(dirname($default, 2));
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function tester(): CommandTester
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => json_decode($options['body'], true), 'headers' => $options['headers']];

            return array_shift($this->responses) ?? new MockResponse('{"error":"unexpected"}', ['http_code' => 500]);
        });

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params = []): array|false {
            $this->queries[] = ['sql' => $sql, 'params' => $params];

            return $this->logRows === [] ? false : array_shift($this->logRows);
        });

        // The HTTP mode never touches the model comparison (--modelos, see
        // SmartWaiterAskModelsTest): an uninitialised one would fail loudly if it did.
        $comparison = (new \ReflectionClass(SmartWaiterModelComparison::class))->newInstanceWithoutConstructor();

        return new CommandTester(new SmartWaiterAskCommand($http, $this->clock, $connection, $comparison, $this->dir));
    }

    private function chatReply(string $conversationId, ?string $reply, bool $error = false): MockResponse
    {
        return new MockResponse(json_encode(['conversationId' => $conversationId, 'reply' => $reply, 'error' => $error]));
    }

    private function csv(string $content): string
    {
        file_put_contents($this->dir . '/in.csv', $content);

        return $this->dir . '/in.csv';
    }

    /** @return list<list<string>> */
    private function readOutput(string $path): array
    {
        $content = file_get_contents($path);
        self::assertStringStartsWith("\xEF\xBB\xBF", $content, 'UTF-8 BOM, for Excel');
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($content, 3));
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function testSameLetterReusesTheConversationAndANewLetterStartsAnother(): void
    {
        $in = $this->csv("conversacion;pregunta;idioma\nA;Soy alérgico al pan;es\nA;¿Y qué me recomiendas?;\nB;Is the pizza vegan?;en\n");
        $this->responses = [
            $this->chatReply('conv-a', 'No puedo confirmarlo.'),
            $this->chatReply('conv-a', "Ningún plato confirmado;\nconsulta al personal."),
            $this->chatReply('conv-b', 'It is not marked as vegan.'),
        ];

        $tester = $this->tester();
        $tester->execute(['--csv' => $in, '--out' => $this->dir . '/out.csv', '--url' => 'http://localhost:8000']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(3, $this->requests);
        foreach ($this->requests as $request) {
            self::assertSame('http://localhost:8000/r/david-test/chat', $request['url']);
        }
        self::assertSame(['message' => 'Soy alérgico al pan', 'conversationId' => null, 'locale' => 'es'], $this->requests[0]['body'], 'same body as the bubble');
        self::assertSame(['message' => '¿Y qué me recomiendas?', 'conversationId' => 'conv-a', 'locale' => 'es'], $this->requests[1]['body'], 'A again: same conversation; empty idioma = es');
        self::assertSame(['message' => 'Is the pizza vegan?', 'conversationId' => null, 'locale' => 'en'], $this->requests[2]['body'], 'B: a new conversation, like a new tab');
        self::assertContains('Accept-Language: en', $this->requests[2]['headers']);

        self::assertSame([
            ['conversacion', 'pregunta', 'idioma', 'respuesta', 'modelo', 'error'],
            ['A', 'Soy alérgico al pan', 'es', 'No puedo confirmarlo.', '', ''],
            ['A', '¿Y qué me recomiendas?', 'es', "Ningún plato confirmado;\nconsulta al personal.", '', ''],
            ['B', 'Is the pizza vegan?', 'en', 'It is not marked as vegan.', '', ''],
        ], $this->readOutput($this->dir . '/out.csv'));
    }

    public function testWithoutOutItAlwaysOverwritesTheSameFile(): void
    {
        $in = $this->csv("conversacion;pregunta\nA;primera\nA;otra\n");
        $this->responses = [$this->chatReply('c', 'r1'), $this->chatReply('c', 'r2')];
        $this->tester()->execute(['--csv' => $in, '--pause' => '0']);

        $in = $this->csv("conversacion;pregunta\nB;segunda\n");
        $this->responses = [$this->chatReply('d', 'r3')];
        $this->tester()->execute(['--csv' => $in, '--pause' => '0']);

        $out = $this->dir . '/' . SmartWaiterAskCommand::DEFAULT_OUT;
        self::assertSame('var/smart-waiter-ask/respuestas.csv', SmartWaiterAskCommand::DEFAULT_OUT);
        self::assertSame([
            ['conversacion', 'pregunta', 'idioma', 'respuesta', 'modelo', 'error'],
            ['B', 'segunda', 'es', 'r3', '', ''],
        ], $this->readOutput($out), 'only the last run is in it');
        self::assertSame(['respuestas.csv'], array_values(array_diff(scandir(dirname($out)), ['.', '..'])), 'one file, no dated copies');
    }

    public function testPausesBetweenQuestionsButNotBeforeTheFirst(): void
    {
        $in = $this->csv("conversacion;pregunta\nA;uno\nA;dos\nA;tres\n");
        $this->responses = [$this->chatReply('c', 'r1'), $this->chatReply('c', 'r2'), $this->chatReply('c', 'r3')];

        $this->tester()->execute(['--csv' => $in, '--out' => $this->dir . '/out.csv', '--pause' => '4']);

        self::assertEquals(new \DateTimeImmutable('2026-11-08 12:00:08'), $this->clock->now(), '2 pauses of 4 s');
    }

    public function testErrorsGoToTheErrorColumnAndARateLimitIsRetriedOnce(): void
    {
        $in = $this->csv("conversacion;pregunta;idioma\nA;uno;es\nA;dos;es\nA;tres;es\n");
        $this->responses = [
            $this->chatReply('conv-a', null, error: true),
            new MockResponse('{"error":"rate_limited"}', ['http_code' => 429]),
            $this->chatReply('conv-a', 'por fin'),
            new MockResponse('{"error":"unavailable"}', ['http_code' => 503]),
        ];

        $tester = $this->tester();
        $tester->execute(['--csv' => $in, '--out' => $this->dir . '/out.csv', '--pause' => '0']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $rows = $this->readOutput($this->dir . '/out.csv');
        self::assertSame(['A', 'uno', 'es', '', '', 'sin respuesta (todos los proveedores de IA fallaron o cortaron)'], $rows[1]);
        self::assertSame(['A', 'dos', 'es', 'por fin', '', ''], $rows[2], '429 → wait and retry once');
        self::assertSame(['A', 'tres', 'es', '', '', 'HTTP 503 unavailable'], $rows[3]);
        self::assertSame('conv-a', $this->requests[1]['body']['conversationId'], 'even a failed reply hands back the conversation id');
        self::assertEquals(new \DateTimeImmutable('2026-11-08 12:01:01'), $this->clock->now(), 'the 61 s rate-limit wait');
    }

    public function testTheModelColumnSaysWhoAnsweredEachQuestion(): void
    {
        $in = $this->csv("conversacion;pregunta\nA;uno\nA;dos\nB;tres\nB;cuatro\n");
        $this->responses = [
            $this->chatReply('conv-a', 'r1'),
            $this->chatReply('conv-a', 'r2'),
            $this->chatReply('conv-b', null, error: true),
            new MockResponse('{"error":"message_too_long"}', ['http_code' => 400]),
        ];
        $this->logRows = [
            ['id' => 101, 'provider_id' => 'gemini-flash', 'model' => 'gemini-flash-latest', 'success' => true, 'failure_reason' => null, 'attempts_count' => 1],
            ['id' => 103, 'provider_id' => 'gemini-flash-lite', 'model' => 'gemini-flash-lite-latest', 'success' => true, 'failure_reason' => null, 'attempts_count' => 2],
            ['id' => 104, 'provider_id' => null, 'model' => null, 'success' => false, 'failure_reason' => 'cut_off', 'attempts_count' => 3],
        ];

        $this->tester()->execute(['--csv' => $in, '--out' => $this->dir . '/out.csv', '--pause' => '0']);

        $rows = $this->readOutput($this->dir . '/out.csv');
        self::assertSame('modelo', $rows[0][4]);
        self::assertSame('gemini-flash (gemini-flash-latest)', $rows[1][4]);
        self::assertSame('gemini-flash-lite (gemini-flash-lite-latest) — 2º intento', $rows[2][4], 'the first provider failed or was cut off');
        self::assertSame('sin respuesta (cut_off)', $rows[3][4]);
        self::assertSame('', $rows[4][4], 'a 400 never reached the AI: no row, nothing to say');

        // Looked up by the conversation id the server returned, past the last row already reported.
        self::assertCount(3, $this->queries, 'no lookup without a conversation id');
        self::assertStringStartsWith('SELECT', trim($this->queries[0]['sql']));
        self::assertStringContainsString('FROM smart_waiter_exchange_log', $this->queries[0]['sql']);
        self::assertSame(['conv-a', 0], $this->queries[0]['params']);
        self::assertSame(['conv-a', 101], $this->queries[1]['params'], 'never the previous question\'s row');
        self::assertSame(['conv-b', 0], $this->queries[2]['params']);
    }

    public function testNoNewLogRowLeavesTheModelColumnEmpty(): void
    {
        $in = $this->csv("conversacion;pregunta\nA;uno\n");
        $this->responses = [$this->chatReply('conv-a', 'r1')];
        $this->logRows = [false];

        $this->tester()->execute(['--csv' => $in, '--out' => $this->dir . '/out.csv', '--pause' => '0']);

        self::assertSame(['A', 'uno', 'es', 'r1', '', ''], $this->readOutput($this->dir . '/out.csv')[1]);
    }

    public function testRefusesAnythingButLocalhost(): void
    {
        $in = $this->csv("conversacion;pregunta\nA;hola\n");

        foreach (['https://qrmenu.example.com', 'http://192.168.1.10:8000', 'http://localhost.evil.com', 'ftp://localhost'] as $url) {
            $tester = $this->tester();
            $tester->execute(['--csv' => $in, '--out' => $this->dir . '/out.csv', '--url' => $url]);
            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $url);
        }

        self::assertSame([], $this->requests, 'not a single request sent');
        self::assertFileDoesNotExist($this->dir . '/out.csv');
    }

    public function testACsvWithoutTheColumnsIsRejected(): void
    {
        $in = $this->csv("letra;texto\nA;hola\n");

        $tester = $this->tester();
        $tester->execute(['--csv' => $in, '--out' => $this->dir . '/out.csv']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->requests);
    }

    public function testReadsAnExcelSavedCsvWithBom(): void
    {
        $in = $this->csv("\xEF\xBB\xBFconversacion;pregunta;idioma\r\nA;¿Qué lleva la paella?;es\r\n");
        $this->responses = [$this->chatReply('c', 'Arroz.')];

        $this->tester()->execute(['--csv' => $in, '--out' => $this->dir . '/out.csv']);

        self::assertSame('¿Qué lleva la paella?', $this->requests[0]['body']['message']);
    }

    public function testTheSampleCsvHasEveryBlockOfTodaysTestInItsOwnConversation(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/' . SmartWaiterAskCommand::DEFAULT_CSV);
        $lines = array_values(array_filter(explode("\n", $content)));
        self::assertSame('conversacion;pregunta;idioma', $lines[0]);

        $byConversation = [];
        foreach (array_slice($lines, 1) as $line) {
            [$letter, $question] = explode(';', $line);
            $byConversation[$letter][] = $question;
        }
        self::assertSame(['A', 'B', 'C', 'D', 'E'], array_keys($byConversation));
        self::assertCount(10, $byConversation['E'], 'celíaco + 8 questions + recommend');
        self::assertSame('Soy celíaco.', $byConversation['E'][0]);
    }
}
