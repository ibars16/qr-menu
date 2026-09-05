<?php

namespace App\Command;

use App\Repository\RestaurantRepository;
use App\Service\Upload\OrphanUploadFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finds and (optionally) deletes files in public/uploads/logos/ that no
 * Restaurant.logo references any more — e.g. left behind by a logo
 * replacement before 1977ec2 started cleaning up the previous file itself.
 * Restaurant.logo (a bare filename, never a path) is the only place in the
 * schema that references this folder — confirmed by grepping the whole
 * schema's columns, not assumed.
 *
 * Safe by default: dry-run unless --force is passed, and --dry-run always
 * wins if both are given. Never builds a path from a DB value or from
 * anything but a real directory listing, and refuses to treat "zero
 * referenced logos" as "delete everything" when restaurants actually exist
 * — that shape only occurs if the query is broken, not from an empty result
 * that's expected.
 */
#[AsCommand(
    name: 'app:logos:clean-orphans',
    description: 'Lists (or, with --force, deletes) logo files in public/uploads/logos/ that no restaurant references any more.',
)]
final class CleanOrphanLogosCommand extends Command
{
    public function __construct(
        private readonly RestaurantRepository $restaurantRepo,
        private readonly OrphanUploadFinder    $orphanFinder,
        private readonly string                $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete the orphan files found (without this, only lists them)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without deleting anything (the default; passing it explicitly always overrides --force)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $force = (bool) $input->getOption('force') && !$input->getOption('dry-run');

        $logoDir = $this->projectDir . '/public/uploads/logos';

        if (!is_dir($logoDir)) {
            $io->success('public/uploads/logos/ does not exist — nothing to clean.');
            return Command::SUCCESS;
        }

        $diskFilenames = $this->listFiles($logoDir);
        if (empty($diskFilenames)) {
            $io->success('public/uploads/logos/ is empty — nothing to clean.');
            return Command::SUCCESS;
        }

        try {
            $referencedNames = array_values(array_filter(
                $this->restaurantRepo->createQueryBuilder('r')
                    ->select('r.logo')
                    ->where('r.logo IS NOT NULL')
                    ->getQuery()
                    ->getSingleColumnResult()
            ));
            $restaurantCount = (int) $this->restaurantRepo->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable $e) {
            $io->error('Could not read referenced logos from the database — aborting without touching disk. ' . $e->getMessage());
            return Command::FAILURE;
        }

        // A restaurant table that isn't empty but yields zero referenced
        // logos is exactly the shape a broken query (wrong column, wrong
        // table) would produce, and it would make every file on disk look
        // orphaned. Refuse to proceed rather than risk deleting live logos.
        if ($restaurantCount > 0 && empty($referencedNames)) {
            $io->error(sprintf(
                '%d restaurant(s) exist but none reference a logo — refusing to proceed in case the query is broken. If no restaurant genuinely has a logo set, verify manually before re-running.',
                $restaurantCount
            ));
            return Command::FAILURE;
        }

        $orphans = $this->orphanFinder->findOrphans($diskFilenames, $referencedNames);

        if (empty($orphans)) {
            $io->success(sprintf('%d file(s) in public/uploads/logos/, all referenced — nothing to clean.', count($diskFilenames)));
            return Command::SUCCESS;
        }

        $rows = [];
        $totalBytes = 0;
        foreach ($orphans as $filename) {
            $path = $logoDir . '/' . $filename;
            $size = filesize($path);
            $totalBytes += $size;
            $rows[] = [$filename, $this->formatBytes($size), date('Y-m-d H:i:s', filemtime($path))];
        }

        $io->table(['Archivo', 'Tamaño', 'Modificado'], $rows);
        $io->writeln(sprintf(
            '%d de %d archivo(s) en public/uploads/logos/ no están referenciados (%s recuperables).',
            count($orphans), count($diskFilenames), $this->formatBytes($totalBytes)
        ));

        if (!$force) {
            $io->note('Dry-run: no se ha borrado nada. Vuelve a ejecutar con --force para borrarlos.');
            return Command::SUCCESS;
        }

        $deleted = 0;
        $errors = [];
        foreach ($orphans as $filename) {
            $path = $logoDir . '/' . $filename;
            if (!is_file($path)) {
                continue; // gone already — nothing to do
            }

            try {
                if (unlink($path)) {
                    $deleted++;
                } else {
                    $errors[] = $filename;
                }
            } catch (\Throwable $e) {
                $errors[] = $filename . ': ' . $e->getMessage();
            }
        }

        foreach ($errors as $error) {
            $io->writeln('  <error>No se pudo borrar: ' . $error . '</error>');
        }

        $io->success(sprintf('%d archivo(s) borrado(s), %d error(es).', $deleted, count($errors)));

        return Command::SUCCESS;
    }

    /** @return list<string> basenames of regular files directly inside $dir */
    private function listFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($dir . '/' . $entry)) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 2) . ' MB';
    }
}
