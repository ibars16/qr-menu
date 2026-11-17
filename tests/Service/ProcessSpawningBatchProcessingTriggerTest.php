<?php

namespace App\Tests\Service;

use App\Entity\MenuImportBatch;
use App\Entity\Restaurant;
use App\Service\ProcessSpawningBatchProcessingTrigger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The background spawn must use the CLI php the finder resolves, never
 * PHP_BINARY directly (under php-fpm that's php-fpm itself, which can't run
 * bin/console). A fake "php" shell script stands in for the binary and only
 * records its arguments — the real import command never runs, no DB, no AI.
 */
final class ProcessSpawningBatchProcessingTriggerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/spawn-trigger-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workDir);
    }

    public function testSpawnsTheBinaryTheFinderResolves(): void
    {
        $argsFile = $this->workDir . '/args.txt';
        $fakePhp = $this->workDir . '/fake-php';
        file_put_contents($fakePhp, "#!/bin/sh\necho \"\$@\" > " . escapeshellarg($argsFile) . "\n");
        chmod($fakePhp, 0755);

        $logger = $this->logger();
        $trigger = new ProcessSpawningBatchProcessingTrigger($this->workDir, 'prod', $logger, $this->finder($fakePhp));
        $trigger->trigger($this->batch(42));

        // The real work is backgrounded: give the detached fake binary a moment.
        for ($i = 0; $i < 50 && !is_file($argsFile); ++$i) {
            usleep(20_000);
        }

        self::assertFileExists($argsFile);
        self::assertSame($this->workDir . '/bin/console app:menu-import:process-batch 42 --env=prod', trim(file_get_contents($argsFile)));
        self::assertSame([], $logger->records);
    }

    public function testLogsAnErrorAndSpawnsNothingWhenNoPhpIsFound(): void
    {
        $logger = $this->logger();
        $trigger = new ProcessSpawningBatchProcessingTrigger($this->workDir, 'prod', $logger, $this->finder(false));
        $trigger->trigger($this->batch(7));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame(7, $logger->records[0]['context']['batchId']);
    }

    public function testDefaultFinderNeverResolvesToPhpFpm(): void
    {
        $php = (new PhpExecutableFinder())->find(false);

        self::assertIsString($php);
        self::assertStringNotContainsString('php-fpm', $php);
    }

    private function finder(string|false $result): PhpExecutableFinder
    {
        return new class($result) extends PhpExecutableFinder {
            public function __construct(private readonly string|false $result)
            {
                parent::__construct();
            }

            public function find(bool $includeArgs = true): string|false
            {
                return $this->result;
            }
        };
    }

    private function batch(int $id): MenuImportBatch
    {
        $batch = new MenuImportBatch(new Restaurant());
        (new \ReflectionProperty(MenuImportBatch::class, 'id'))->setValue($batch, $id);

        return $batch;
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
