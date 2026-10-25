<?php

namespace App\Command;

use App\Service\AI\AIProviderFactory;
use App\Service\AllergenBackfill\AllergenDecision;
use App\Service\AllergenBackfill\IngredientAllergenBackfill;
use App\Repository\RestaurantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Completes ingredient allergen links with AI — see
 * IngredientAllergenBackfill. Re-runnable: anything already reviewed (by
 * the YAML, a person or a previous run) or waiting for review in
 * AllergenReviewQueue is skipped, so a run interrupted by a quota limit
 * just carries on next time without asking again about what's pending.
 *
 * Writes, under --out: <stamp>-revision.csv / .md (the short list a person
 * should check: every review item and suggestion, with the reason) and
 * <stamp>-todo.csv (every decision, applied ones included).
 *
 * --recheck-applied makes no AI call: it runs the current rules over what
 * earlier runs already applied and withdraws what would now go to review.
 * --import-review-list queues the review items of older -todo.csv files.
 */
#[AsCommand(
    name: 'app:allergens:backfill',
    description: 'Classifies ingredient allergens with Groq (two passes + keyword rules) and applies only the unambiguous ones',
)]
final class AllergenBackfillCommand extends Command
{
    public function __construct(
        private readonly IngredientAllergenBackfill $backfill,
        private readonly RestaurantRepository $restaurantRepository,
        private readonly AIProviderFactory $providerFactory,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('global', null, InputOption::VALUE_REQUIRED, '"all", "none", or a file with one global ingredient code per line', 'all')
            ->addOption('private', null, InputOption::VALUE_REQUIRED, '"all", "none", or comma-separated restaurant slugs', 'all')
            ->addOption('private-codes', null, InputOption::VALUE_REQUIRED, 'Only these private ingredients: a file with one code per line')
            ->addOption('suggest-protected', null, InputOption::VALUE_NONE, 'Also classify YAML/hand-set ingredients — differences only go to the review list as suggestions')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max ingredients this run (quota/cost control)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Ingredients per AI call', 20)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Call the AI and write the files, but change nothing in the database')
            ->addOption('include-pending', null, InputOption::VALUE_NONE, 'Also send the ones already waiting for review (e.g. after changing the rules)')
            ->addOption('import-review-list', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'No AI: queue the review items of these -todo.csv files (oldest first; the latest row per ingredient wins)')
            ->addOption('recheck-applied', null, InputOption::VALUE_NONE, 'No AI: re-check everything already applied by AI against the current rules and withdraw what would now go to review')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory, relative to the project', 'var/allergen-backfill');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('import-review-list') !== []) {
            return $this->importReviewList($io, $input->getOption('import-review-list'), (bool) $input->getOption('dry-run'));
        }
        if ($input->getOption('recheck-applied')) {
            return $this->recheckApplied($io, (bool) $input->getOption('dry-run'), (string) $input->getOption('out'));
        }

        $global = (string) $input->getOption('global');
        $globalCodes = match ($global) {
            'all' => null,
            'none' => [],
            default => $this->readCodes($global),
        };
        if ($globalCodes === false) {
            $io->error("Can't read the codes file \"{$global}\".");
            return Command::FAILURE;
        }

        $private = (string) $input->getOption('private');
        $restaurantIds = match ($private) {
            'all' => null,
            'none' => [],
            default => array_map(fn (string $slug) => $this->restaurantRepository->findOneBy(['slug' => trim($slug)])?->getId() ?? -1, explode(',', $private)),
        };
        if (is_array($restaurantIds) && in_array(-1, $restaurantIds, true)) {
            $io->error("Unknown restaurant slug in --private=\"{$private}\".");
            return Command::FAILURE;
        }

        $privateCodes = null;
        if ($input->getOption('private-codes') !== null) {
            $privateCodes = $this->readCodes((string) $input->getOption('private-codes'));
            if ($privateCodes === false) {
                $io->error('Can\'t read the --private-codes file.');
                return Command::FAILURE;
            }
        }

        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $subjects = $this->backfill->findSubjects($globalCodes, $restaurantIds, (bool) $input->getOption('suggest-protected'), $limit, $privateCodes, (bool) $input->getOption('include-pending'));
        $io->title(sprintf('Allergen backfill — %d ingredient(s)%s', count($subjects), $dryRun ? ', dry run (database untouched)' : ''));
        if ($subjects === []) {
            $io->success('Nothing to classify.');
            return Command::SUCCESS;
        }

        $failedBatches = 0;
        $results = $this->backfill->run($subjects, max(1, (int) $input->getOption('batch-size')), $dryRun, function (int $done, int $total, ?\Throwable $error) use ($io, &$failedBatches) {
            if ($error) {
                $failedBatches++;
                $io->writeln(sprintf('  <error>batch failed (%d/%d): %s</error>', $done, $total, $error->getMessage()));
            } else {
                $io->writeln(sprintf('  %d/%d', $done, $total));
            }
        });

        $counts = array_fill_keys([AllergenDecision::APPLIED, AllergenDecision::NO_ALLERGENS, AllergenDecision::REVIEW, AllergenDecision::SUGGESTION, AllergenDecision::UNCHANGED, AllergenDecision::FAILED], 0);
        foreach ($results as $result) {
            $counts[$result['decision']->outcome]++;
        }

        [$reviewCsv, $reviewMd, $allCsv] = $this->writeFiles($results, (string) $input->getOption('out'));

        $usage = $this->backfill->getUsage();
        $pricing = $this->providerFactory->getPricing('groq-llama');
        $cost = ($usage['promptTokens'] * ($pricing['input'] ?? 0) + $usage['completionTokens'] * ($pricing['output'] ?? 0)) / 1_000_000;

        $io->table(['Outcome', 'Count'], [
            ['Applied (with allergens)', $counts[AllergenDecision::APPLIED]],
            ['Applied (no allergens)', $counts[AllergenDecision::NO_ALLERGENS]],
            ['To review', $counts[AllergenDecision::REVIEW]],
            ['Suggestions on YAML/hand-set', $counts[AllergenDecision::SUGGESTION]],
            ['YAML/hand-set, AI agrees', $counts[AllergenDecision::UNCHANGED]],
            ['Failed (retried next run)', $counts[AllergenDecision::FAILED]],
            ['Failed batches', $failedBatches],
            ['AI calls', $usage['calls']],
            ['Tokens in / out', $usage['promptTokens'] . ' / ' . $usage['completionTokens']],
            ['Estimated cost (USD)', sprintf('%.4f', $cost)],
        ]);
        $io->writeln(['Review list: ' . $reviewCsv, '             ' . $reviewMd, 'All decisions: ' . $allCsv]);

        return Command::SUCCESS;
    }

    /** @param list<string> $files */
    private function importReviewList(SymfonyStyle $io, array $files, bool $dryRun): int
    {
        $latest = [];
        foreach ($files as $file) {
            $path = str_starts_with($file, '/') ? $file : $this->projectDir . '/' . $file;
            $fh = is_readable($path) ? fopen($path, 'r') : false;
            if ($fh === false) {
                $io->error("Can't read \"{$file}\".");
                return Command::FAILURE;
            }
            $header = fgetcsv($fh, null, ',', '"', '');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
                $row = array_combine($header, $row);
                $latest[$row['tipo'] . ':' . $row['id']] = $row;
            }
            fclose($fh);
        }
        $rows = array_values(array_filter($latest, static fn (array $r) => in_array($r['resultado'], [AllergenDecision::REVIEW, AllergenDecision::SUGGESTION], true)));

        $io->title(sprintf('Allergen backfill — importing %d review item(s) into the queue%s', count($rows), $dryRun ? ', dry run (database untouched)' : ''));
        $counts = $this->backfill->importReviewList($rows, $dryRun);
        $io->table(['Outcome', 'Count'], [
            ['Queued for review', $counts['queued']],
            ['Re-applied (current rules allow it)', $counts['reapplied']],
            ['Skipped (reviewed since, already queued or gone)', $counts['skipped']],
        ]);

        return Command::SUCCESS;
    }

    private function recheckApplied(SymfonyStyle $io, bool $dryRun, string $outDir): int
    {
        $io->title('Allergen backfill — re-checking AI-applied ingredients against the current rules' . ($dryRun ? ', dry run (database untouched)' : ''));
        $results = $this->backfill->recheckApplied($dryRun);

        $rows = [];
        foreach ([IngredientAllergenBackfill::KIND_GLOBAL => 'Global', IngredientAllergenBackfill::KIND_PRIVATE => 'Private'] as $kind => $label) {
            foreach ([true, false] as $withAllergens) {
                $mine = array_filter($results, static fn (array $r) => $r['subject']['kind'] === $kind && ($r['subject']['current'] !== []) === $withAllergens);
                $withdrawn = array_filter($mine, static fn (array $r) => $r['decision']->outcome === AllergenDecision::REVIEW);
                $rows[] = [$label . ($withAllergens ? ', with allergens' : ', no allergens'), count($mine), count($withdrawn)];
            }
        }
        $io->table(['Applied', 'Checked', 'Withdrawn → review'], $rows);

        [$reviewCsv, $reviewMd, $allCsv] = $this->writeFiles($results, $outDir);
        $io->writeln(['Review list: ' . $reviewCsv, '             ' . $reviewMd, 'All decisions: ' . $allCsv]);

        return Command::SUCCESS;
    }

    /** @return list<string>|false */
    private function readCodes(string $file): array|false
    {
        $path = str_starts_with($file, '/') ? $file : $this->projectDir . '/' . $file;
        if (!is_readable($path)) {
            return false;
        }

        return array_values(array_filter(array_map('trim', file($path)), static fn (string $l) => $l !== '' && !str_starts_with($l, '#')));
    }

    /**
     * @param  list<array{subject: array<string, mixed>, first: ?array, second: ?array, decision: AllergenDecision}> $results
     * @return array{string, string, string}
     */
    private function writeFiles(array $results, string $outDir): array
    {
        $dir = $this->projectDir . '/' . trim($outDir, '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stamp = date('Ymd-His');

        $header = ['tipo', 'restaurante', 'id', 'codigo', 'nombre', 'idioma', 'alergenos_actuales', 'propuesta_1a_pasada', 'propuesta_revision', 'reglas_esperan', 'reglas_descartan', 'resultado', 'motivo'];
        $rows = [];
        $review = [];
        foreach ($results as $r) {
            $s = $r['subject'];
            $d = $r['decision'];
            $row = [
                $s['kind'] === IngredientAllergenBackfill::KIND_GLOBAL ? 'global' : 'privado',
                $s['restaurant'] ?? '',
                $s['id'],
                $s['code'],
                $s['name'],
                $s['locale'],
                AllergenDecision::format($s['current']),
                $r['first'] !== null ? AllergenDecision::format($r['first']['allergens']) : '—',
                $r['second'] !== null ? AllergenDecision::format($r['second']['allergens']) : '—',
                implode(', ', $s['rules']['expected']),
                implode(', ', $s['rules']['forbidden']),
                $d->outcome,
                implode(' · ', $d->reasons),
            ];
            $rows[] = $row;
            if (in_array($d->outcome, [AllergenDecision::REVIEW, AllergenDecision::SUGGESTION, AllergenDecision::FAILED], true)) {
                $review[] = $row;
            }
        }
        // Suggestions first, then by reason — similar problems together.
        usort($review, static fn (array $a, array $b) => [$a[11] !== AllergenDecision::SUGGESTION, $a[12], $a[4]] <=> [$b[11] !== AllergenDecision::SUGGESTION, $b[12], $b[4]]);

        $reviewCsv = "{$dir}/{$stamp}-revision.csv";
        $allCsv = "{$dir}/{$stamp}-todo.csv";
        foreach ([[$reviewCsv, $review], [$allCsv, $rows]] as [$path, $data]) {
            $fh = fopen($path, 'w');
            fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheets read the accents right
            fputcsv($fh, $header, ',', '"', '');
            foreach ($data as $row) {
                fputcsv($fh, $row, ',', '"', '');
            }
            fclose($fh);
        }

        $reviewMd = "{$dir}/{$stamp}-revision.md";
        $md = "# Alérgenos — lista de revisión ({$stamp})\n\n"
            . "Nada de esta lista se ha aplicado. Las filas «SOLO SUGERENCIA» son ingredientes del YAML o puestos a mano: no se tocan nunca automáticamente.\n\n"
            . "| Tipo | Restaurante | Nombre | Actuales | Propuesta (revisión) | Motivo |\n|---|---|---|---|---|---|\n";
        foreach ($review as $row) {
            $cells = [$row[0], $row[1], $row[4] . ' (' . $row[5] . ')', $row[6], $row[8], $row[12]];
            $md .= '| ' . implode(' | ', array_map(static fn ($c) => str_replace(['|', "\n"], ['\|', ' '], (string) $c), $cells)) . " |\n";
        }
        file_put_contents($reviewMd, $md);

        return [$reviewCsv, $reviewMd, $allCsv];
    }
}
