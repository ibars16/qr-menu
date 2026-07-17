<?php

namespace App\Tests\VisionEval;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Manual-only check that the REAL vision model still transcribes each golden
 * menu fixture the way its expected.json says it should. Costs a real API
 * call per case and is not deterministic in the way a unit test should be —
 * deliberately excluded from the default suite (see the "Project Test Suite"
 * directory exclusion in phpunit.dist.xml).
 *
 * Shells out to `app:menu:eval --env=dev` rather than using KernelTestCase:
 * phpunit.dist.xml forces APP_ENV=test for anything booted in-process, but
 * Elian's celery allergen (from "apio") only exists in the real Global
 * Ingredient Library seeded in the dev database — the test database has
 * none of it. The command's own transaction is always rolled back
 * regardless of which environment runs it (see MenuEvalCommand), so running
 * it against dev here is still fully non-destructive.
 *
 * Run this whenever MenuVisionPromptBuilder changes:
 *   vendor/bin/phpunit tests/VisionEval
 *
 * @group vision-eval
 */
final class MenuEvalCommandTest extends TestCase
{
    public function testElianMenuVisionOutputMatchesGolden(): void
    {
        $this->assertEvalPasses(['tests/fixtures/elian_menu.png'], 'tests/fixtures/elian_menu.expected.json');
    }

    public function testTropicalMenuVisionOutputMatchesGolden(): void
    {
        $this->assertEvalPasses(['tests/fixtures/tropical.png'], 'tests/fixtures/tropical.expected.json');
    }

    /** Multi-page (4 images), bilingual Catalan/Spanish menu. */
    public function testCervelloMenuVisionOutputMatchesGolden(): void
    {
        $this->assertEvalPasses(
            [
                'tests/fixtures/cervello.png',
                'tests/fixtures/cervello1.png',
                'tests/fixtures/cervello2.png',
                'tests/fixtures/cervello3.png',
            ],
            'tests/fixtures/cervello.expected.json'
        );
    }

    /** @param string[] $images */
    private function assertEvalPasses(array $images, string $golden): void
    {
        $projectDir = dirname(__DIR__, 2);

        $process = new Process([
            'php', 'bin/console', 'app:menu:eval',
            ...$images,
            '--golden=' . $golden,
            '--env=dev',
        ], $projectDir);
        $process->setTimeout(180);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
    }
}
