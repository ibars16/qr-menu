<?php

namespace App\Service;

use App\Entity\MenuImportBatch;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Today's implementation of BatchProcessingTriggerInterface: spawns
 * `app:menu-import:process-batch <id>` as a detached background process and
 * returns immediately, so the upload request doesn't block for however long
 * extraction+assembly actually takes (potentially 10s of seconds for a
 * multi-photo upload). MenuImportPipeline already records every failure onto
 * the batch/page rows themselves, which is what the status page actually
 * reads.
 *
 * The command is wrapped in `nohup ... &` and run through a shell so the PID
 * Symfony's Process object actually tracks is the tiny wrapper shell, which
 * exits almost instantly after backgrounding the real work — not the
 * long-running php process itself. This matters because Process::__destruct()
 * sends SIGTERM to whatever PID it's still tracking once the object goes out
 * of scope, which happens the moment trigger() returns; without the wrapper,
 * the console command was being killed within milliseconds of starting.
 *
 * The PHP binary comes from PhpExecutableFinder, not PHP_BINARY: under
 * php-fpm PHP_BINARY is the php-fpm executable itself, which can't run
 * bin/console, and since the spawn is backgrounded with its output thrown
 * away the batch would just sit at UPLOADED forever, silently. Under
 * `php -S` / CLI the finder returns PHP_BINARY as before; under fpm it finds
 * the CLI php next to it (PHP_BINDIR/php), or the PHP_BINARY env var if set.
 *
 * This is a real, working stand-in for a proper queue, not a shortcut
 * pretending to be one — see BatchProcessingTriggerInterface's docblock for
 * exactly what changes when a Messenger worker becomes available.
 */
final class ProcessSpawningBatchProcessingTrigger implements BatchProcessingTriggerInterface
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $kernelEnvironment,
        private readonly LoggerInterface $logger,
        private readonly PhpExecutableFinder $phpFinder = new PhpExecutableFinder(),
    ) {}

    public function trigger(MenuImportBatch $batch): void
    {
        $php = $this->phpFinder->find(false);
        if (false === $php || '' === $php) {
            // Batch stays at UPLOADED; `app:menu-import:process-batch` can still finish it by hand.
            $this->logger->error('No CLI PHP binary found to run background menu-import processing', ['batchId' => $batch->getId()]);

            return;
        }

        $commandLine = sprintf(
            'nohup %s %s app:menu-import:process-batch %d --env=%s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($this->projectDir . '/bin/console'),
            $batch->getId(),
            escapeshellarg($this->kernelEnvironment),
        );

        $process = Process::fromShellCommandline($commandLine);
        $process->setWorkingDirectory($this->projectDir);
        $process->setTimeout(5);

        try {
            // Blocking on purpose: this only waits for the wrapper shell to
            // fork+background the real command, which returns near-instantly.
            $process->run();
        } catch (\Throwable $e) {
            // The upload itself must still succeed even if the background
            // process couldn't be spawned at all (e.g. a hardened
            // environment that blocks proc_open) — the batch is left at
            // UPLOADED, which the manual `app:menu-import:process-batch`
            // command can still finish by hand.
            $this->logger->error('Failed to spawn background menu-import processing', ['batchId' => $batch->getId(), 'exception' => $e]);
        }
    }
}
