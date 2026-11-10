<?php

namespace App\Tests\Command;

use App\Command\SmartWaiterAskCommand;
use App\Entity\Restaurant;
use App\Repository\RestaurantRepository;
use App\Service\MenuPreferencesResolver;
use App\Service\SmartWaiterModelComparison;
use App\Service\SmartWaiterReplyCleaner;
use App\Tests\Service\SmartWaiterFakeAiTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * app:smart-waiter:ask --modelos: every question to every model, with no
 * fallback, each model with its own conversations, and exactly what the
 * customer chat would send. FAKE AI only (SmartWaiterFakeAiTrait — no
 * request can reach a real API), MockClock for the pauses, no HTTP to the
 * chat, no DB.
 */
final class SmartWaiterAskModelsTest extends TestCase
{
    use SmartWaiterFakeAiTrait;

    private const FLASH = self::GEMINI . '/gemini-flash-latest';
    private const FLASH_LITE = self::GEMINI . '/gemini-flash-lite-latest';

    private string $dir;
    private MockClock $clock;
    private Restaurant $theRestaurant;

    /** @var list<string> URLs the command itself requested over HTTP (the chat endpoint) — must stay empty */
    private array $chatRequests = [];

    protected function setUp(): void
    {
        $this->fakeAiSetUp();
        $this->dir = sys_get_temp_dir() . '/sw-ask-models-test-' . uniqid();
        mkdir($this->dir);
        $this->clock = new MockClock('2026-11-09 12:00:00');
        $this->theRestaurant = $this->restaurant();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        $this->fakeAiTearDown();
    }

    private function tester(): CommandTester
    {
        $chatHttp = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->chatRequests[] = $url;

            return new MockResponse('{"error":"unexpected"}', ['http_code' => 500]);
        });

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method(self::anything());

        $restaurants = $this->createStub(RestaurantRepository::class);
        $restaurants->method('findOneBy')->willReturnCallback(
            fn (array $criteria) => ($criteria['slug'] ?? null) === 'david-test' ? $this->theRestaurant : null,
        );

        $factory = $this->factory();
        $comparison = new SmartWaiterModelComparison(
            $this->requestBuilder(),
            $factory,
            $restaurants,
            new MenuPreferencesResolver(dirname(__DIR__, 2)),
            new SmartWaiterReplyCleaner(),
            $this->needsDetector($factory),
            $this->staffNote(),
        );

        return new CommandTester(new SmartWaiterAskCommand($chatHttp, $this->clock, $connection, $comparison, $this->dir));
    }

    private function runCommand(string $csv, array $options): CommandTester
    {
        file_put_contents($this->dir . '/in.csv', $csv);
        $tester = $this->tester();
        $tester->execute(['--csv' => $this->dir . '/in.csv', '--out' => $this->dir . '/out.csv'] + $options);

        return $tester;
    }

    /** @return list<list<string>> */
    private function outputRows(): array
    {
        $content = file_get_contents($this->dir . '/out.csv');
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

    /** @return list<array> this model's requests, in order */
    private function requestsTo(string $model): array
    {
        return array_values(array_filter($this->requests, static fn (array $r) => $r['model'] === $model));
    }

    public function testOneAnswerAndOneErrorColumnPerModelInTheOrderGiven(): void
    {
        $this->replies = [
            self::GROQ => [['Groq 1', 'stop'], ['Groq 2', 'stop']],
            self::FLASH => [['Flash 1', 'STOP'], ['Flash 2', 'STOP']],
            self::FLASH_LITE => [['Lite 1', 'STOP'], ['Lite 2', 'STOP']],
        ];

        $tester = $this->runCommand("conversacion;pregunta;idioma\nA;¿Qué entrantes hay?;es\nB;Is there fish?;en\n", ['--modelos' => 'groq-llama,gemini-flash,gemini-flash-lite']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            ['conversacion', 'pregunta', 'idioma', 'groq-llama respuesta', 'groq-llama error', 'gemini-flash respuesta', 'gemini-flash error', 'gemini-flash-lite respuesta', 'gemini-flash-lite error'],
            ['A', '¿Qué entrantes hay?', 'es', 'Groq 1', '', 'Flash 1', '', 'Lite 1', ''],
            ['B', 'Is there fish?', 'en', 'Groq 2', '', 'Flash 2', '', 'Lite 2', ''],
        ], $this->outputRows());
        self::assertSame(['openai/gpt-oss-120b', 'gemini-flash-latest', 'gemini-flash-lite-latest', 'openai/gpt-oss-120b', 'gemini-flash-latest', 'gemini-flash-lite-latest'], array_column($this->requests, 'model'));
        self::assertSame([], $this->chatRequests, 'no HTTP to the chat');
    }

    public function testNoFallbackAFailingModelOnlyFillsItsOwnErrorColumn(): void
    {
        // Groq unscripted → 500. Nobody steps in for it.
        $this->replies = [self::FLASH_LITE => [['Lite 1', 'STOP']]];

        $this->runCommand("conversacion;pregunta\nA;¿Qué entrantes hay?\n", ['--modelos' => 'groq-llama,gemini-flash-lite']);

        $row = $this->outputRows()[1];
        self::assertSame('', $row[3]);
        self::assertNotSame('', $row[4], 'Groq\'s failure, in its own column');
        self::assertSame(['Lite 1', ''], [$row[5], $row[6]]);
        self::assertSame([self::GROQ, self::GEMINI], $this->hosts(), 'exactly one call per model: no other provider tried');
    }

    public function testEachModelHasItsOwnConversationsAndEachLetterIsANewOne(): void
    {
        $this->replies = [
            self::GROQ => [['Groq A1', 'stop'], ['Groq A2', 'stop'], ['Groq E1', 'stop']],
            self::FLASH_LITE => [['Lite A1', 'STOP'], ['Lite A2', 'STOP'], ['Lite E1', 'STOP']],
        ];

        $this->runCommand("conversacion;pregunta\nA;Soy alérgico al pan\nA;¿Y qué me recomiendas?\nE;¿Hay algo picante?\n", ['--modelos' => 'groq-llama,gemini-flash-lite']);

        $groq = $this->requestsTo('openai/gpt-oss-120b');
        $lite = $this->requestsTo('gemini-flash-lite-latest');
        self::assertSame([['user', 'Soy alérgico al pan'], ['assistant', 'Groq A1'], ['user', '¿Y qué me recomiendas?']], $this->sentTurns($groq[1]), 'only Groq\'s own reply');
        self::assertSame([['user', 'Soy alérgico al pan'], ['model', 'Lite A1'], ['user', '¿Y qué me recomiendas?']], $this->sentTurns($lite[1]), 'only Gemini\'s own reply');
        self::assertSame([['user', '¿Hay algo picante?']], $this->sentTurns($groq[2]), 'E: a new conversation');
        self::assertSame([['user', '¿Hay algo picante?']], $this->sentTurns($lite[2]));
    }

    public function testEachModelGetsExactlyWhatTheCustomerChatWouldSend(): void
    {
        $questions = ['Soy alérgico al pan', '¿Y qué me recomiendas?'];

        // The customer chat, answered by Gemini flash-lite (Groq, first in
        // smart_waiter_first, is unscripted here and fails).
        $this->replies = [self::GEMINI => [['Respuesta 1', 'STOP'], ['Respuesta 2', 'STOP']]];
        $chat = $this->service();
        $conversationId = $chat->reply($this->theRestaurant, 'es', 'EUR', null, $questions[0])['conversationId'];
        $chat->reply($this->theRestaurant, 'es', 'EUR', $conversationId, $questions[1]);
        $chatSecond = $this->requestsTo('gemini-flash-lite-latest')[1];

        // The comparison, same questions, same replies.
        $this->requests = [];
        $this->replies = [self::GEMINI => [['Respuesta 1', 'STOP'], ['Respuesta 2', 'STOP']]];
        $this->runCommand("conversacion;pregunta;idioma\nA;{$questions[0]};es\nA;{$questions[1]};es\n", ['--modelos' => 'gemini-flash-lite']);
        [, $toolSecond] = $this->requests;

        self::assertSame($this->systemText($chatSecond), $this->systemText($toolSecond), 'the very same instruction and menu');
        self::assertSame($this->sentTurns($chatSecond), $this->sentTurns($toolSecond), 'the very same history');
        self::assertSame($chatSecond['body']['generationConfig'], $toolSecond['body']['generationConfig'], 'same max tokens and temperature');
    }

    public function testACutOffAnswerIsAnErrorAndTheQuestionStillReachesTheNextOne(): void
    {
        $this->replies = [self::GROQ => [['Te recomiendo el carpa', 'length'], ['Ningún plato está confirmado sin gluten.', 'stop']]];

        $this->runCommand("conversacion;pregunta\nA;Soy alérgico al pan\nA;¿Y qué me recomiendas?\n", ['--modelos' => 'groq-llama']);

        $rows = $this->outputRows();
        self::assertSame('Te recomiendo el carpa', $rows[1][3], 'the half answer, to see it');
        self::assertStringStartsWith('cortada (length)', $rows[1][4]);
        self::assertSame(['Ningún plato está confirmado sin gluten.', ''], [$rows[2][3], $rows[2][4]]);

        $second = $this->requests[1];
        self::assertSame([['user', '¿Y qué me recomiendas?']], $this->sentTurns($second), 'like the chat: a cut-off answer never becomes a turn…');
        self::assertSame(['Soy alérgico al pan'], $this->earlierCustomerMessages($second), '…but the question is still said');
    }

    public function testAnswersAreCleanedLikeTheChatsInTheCsvAndInTheHistory(): void
    {
        $this->replies = [self::GROQ => [['Te recomiendo la **ensalada**.', 'stop'], ['Vale.', 'stop']]];

        $this->runCommand("conversacion;pregunta\nA;¿Qué me recomiendas?\nA;¿Y de postre?\n", ['--modelos' => 'groq-llama']);

        self::assertSame('Te recomiendo la ensalada.', $this->outputRows()[1][3]);
        self::assertSame('Te recomiendo la ensalada.', $this->sentTurns($this->requests[1])[1][1]);
    }

    public function testEachModelDetectsTheNeedsItselfWithNoFallback(): void
    {
        $this->needsReplies = [self::GROQ => [['Eres celíaco.', 'stop'], [self::needs(), 'stop']]];
        $this->replies = [
            self::GROQ => [['Groq 2', 'stop']],
            self::FLASH_LITE => [['Lite 1', 'STOP'], ['Lite 2', 'STOP']],
        ];

        $this->runCommand("conversacion;pregunta\nA;Soy celíaco\nA;¿Algo picante?\n", ['--modelos' => 'groq-llama,gemini-flash-lite']);

        $rows = $this->outputRows();
        self::assertSame('', $rows[1][3]);
        self::assertSame('no se pudo comprobar la alergia (respuesta no válida)', $rows[1][4], 'Groq\'s own detection failed: nobody stepped in');
        self::assertSame(['Lite 1', ''], [$rows[1][5], $rows[1][6]]);
        self::assertSame(['Groq 2', ''], [$rows[2][3], $rows[2][4]]);

        self::assertSame(['openai/gpt-oss-120b', 'gemini-flash-lite-latest', 'openai/gpt-oss-120b', 'gemini-flash-lite-latest'], array_column($this->detections, 'model'), 'one detection per model and question, by that model');
        self::assertSame(['gemini-flash-lite-latest', 'openai/gpt-oss-120b', 'gemini-flash-lite-latest'], array_column($this->requests, 'model'), 'no answer without a detection');

        $groqSecond = json_decode($this->sentTurns($this->detections[2])[0][1], true);
        self::assertSame(['Soy celíaco', '¿Algo picante?'], $groqSecond['customer_messages'], 'like the chat: the failed one is detected again');
    }

    public function testTheDetectedNeedsSortTheModelsInstructionLikeTheChats(): void
    {
        $this->needsReplies = [self::FLASH_LITE => [[self::needs(['gluten'], [], true), 'STOP']]];
        $this->replies = [self::FLASH_LITE => [['Vale.', 'STOP']]];

        $this->runCommand("conversacion;pregunta\nA;Soy celíaco\n", ['--modelos' => 'gemini-flash-lite']);

        $system = $this->systemText($this->requests[0]);
        self::assertStringContainsString("- avoids: gluten\n", $system);
        self::assertStringContainsString('- STAFF: never mention the staff', $system);
        self::assertSame(60.0, $this->detections[0]['timeout'], '--espera applies to the small call too');
        self::assertSame("Vale.\n\nNingún plato de nuestra carta está confirmado sin gluten. Antes de pedir, conviene confirmarlo con el personal.", $this->outputRows()[1][3], 'the column shows what the customer would see');
    }

    public function testTheDetectedLanguageIsNamedInTheModelsInstructionLikeTheChats(): void
    {
        $this->needsReplies = [self::FLASH_LITE => [[self::needs([], [], false, false, 'en'), 'STOP']]];
        $this->replies = [self::FLASH_LITE => [['We have flan.', 'STOP']]];

        $this->runCommand("conversacion;pregunta\nN;What do you recommend for dessert?\n", ['--modelos' => 'gemini-flash-lite']);

        $system = $this->systemText($this->requests[0]);
        self::assertStringContainsString('The customer\'s latest message is in English: reply in English', $system);
        self::assertStringContainsString('- Reply in English (rule 10).', $system);
    }

    public function testAnUnknownOrUnconfiguredModelIsRefusedBeforeAnyCall(): void
    {
        // openrouter-free: its key is blank in these tests.
        $tester = $this->runCommand("conversacion;pregunta\nA;Hola\n", ['--modelos' => 'groq-llama,no-existe,openrouter-free']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no-existe, openrouter-free', $tester->getDisplay());
        self::assertSame([], $this->requests);
        self::assertFileDoesNotExist($this->dir . '/out.csv');
    }

    public function testAnUnknownRestaurantIsRefusedBeforeAnyCall(): void
    {
        $tester = $this->runCommand("conversacion;pregunta\nA;Hola\n", ['--modelos' => 'groq-llama', '--slug' => 'otro']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->requests);
    }

    public function testEsperaIsEachCallsTimeoutAndTheChatKeepsItsOwn(): void
    {
        $this->replies = [self::GROQ => [['ok', 'stop']], self::FLASH => [['ok', 'STOP']]];
        $this->runCommand("conversacion;pregunta\nA;Hola\n", ['--modelos' => 'groq-llama,gemini-flash']);
        self::assertSame([60.0, 60.0], array_column($this->requests, 'timeout'), '60 s by default, Gemini and Groq alike');

        $this->requests = [];
        $this->replies = [self::GROQ => [['ok', 'stop']]];
        $this->runCommand("conversacion;pregunta\nA;Hola\n", ['--modelos' => 'groq-llama', '--espera' => '90']);
        self::assertSame([90.0], array_column($this->requests, 'timeout'));

        $this->requests = [];
        $this->replies = [self::GEMINI => [['ok', 'STOP']]];
        $this->service()->reply($this->theRestaurant, 'es', 'EUR', null, 'Hola');
        self::assertSame([20.0], array_values(array_unique(array_column($this->requests, 'timeout'))), 'the customer chat: still 20 s, Groq and Gemini alike');
    }

    public function testThePauseIsPerModelSoOneModelsWaitCountsForTheOthers(): void
    {
        $this->replies = [
            self::GROQ => array_fill(0, 3, ['ok', 'stop']),
            self::FLASH_LITE => array_fill(0, 3, ['ok', 'STOP']),
        ];

        // No --pause: 60 s between two calls to the same model.
        $this->runCommand("conversacion;pregunta\nA;uno\nA;dos\nA;tres\n", ['--modelos' => 'groq-llama,gemini-flash-lite']);

        self::assertEquals(new \DateTimeImmutable('2026-11-09 12:02:00'), $this->clock->now(), '3 questions: 2 waits of 60 s, not 4 nor 6');
        self::assertCount(6, $this->requests);
    }

    public function testAQuestionTheChatWouldRefuseAsTooLongIsNeverSent(): void
    {
        $this->runCommand("conversacion;pregunta\nA;" . str_repeat('a', 801) . "\n", ['--modelos' => 'groq-llama']);

        self::assertSame([], $this->requests);
        self::assertStringContainsString('800', $this->outputRows()[1][4]);
    }

    public function testTheComparisonWritesNoExchangeLog(): void
    {
        $this->replies = [self::GROQ => [['ok', 'stop']]];

        $this->runCommand("conversacion;pregunta\nA;Hola\n", ['--modelos' => 'groq-llama']);

        // The Connection mock fails on any call; the comparison has no
        // EntityManager nor alert mailer at all.
        self::assertSame([], $this->logs);
        self::assertSame([], $this->chatRequests);
    }
}
