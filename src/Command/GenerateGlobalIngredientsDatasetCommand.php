<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Converts a raw Open Food Facts ingredients taxonomy file
 * (https://github.com/openfoodfacts/openfoodfacts-server, taxonomies/food/ingredients.txt,
 * ODbL — attribution required, commercial use permitted) into a clean,
 * de-duplicated CSV that ships with this app at config/global_ingredients.csv.
 *
 * This is the "regenerate the dataset" half of the pipeline — it never
 * touches the database. Run ImportGlobalIngredientsCommand afterwards to
 * actually seed/update the global_ingredient tables from the CSV it produces.
 *
 * Re-run this whenever a fresh copy of the upstream taxonomy is available, or
 * to add a newly-supported language: pass it via --locales and it will be
 * picked up as an extra CSV column, no code changes required beyond that.
 */
#[AsCommand(
    name: 'app:global-ingredients:generate-dataset',
    description: 'Parses a raw Open Food Facts ingredients taxonomy file into config/global_ingredients.csv',
)]
final class GenerateGlobalIngredientsDatasetCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Path to the raw taxonomy file (taxonomies/food/ingredients.txt)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Where to write the clean CSV', 'config/global_ingredients.csv')
            ->addOption('locales', null, InputOption::VALUE_REQUIRED, 'Comma-separated locale codes to extract, in priority order. The first is required for an entry to be kept (it anchors the code/slug); the rest are included when present.', 'en,es');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourcePath = $input->getArgument('source');
        if (!is_readable($sourcePath)) {
            $io->error("Cannot read source file: {$sourcePath}");
            return Command::FAILURE;
        }

        $locales = array_map('trim', explode(',', (string) $input->getOption('locales')));
        $anchorLocale = $locales[0];

        $raw = str_replace("\r\n", "\n", file_get_contents($sourcePath));
        $blocks = preg_split("/\n\s*\n/", $raw);

        $entries = [];
        $seenCodes = [];
        $droppedDuplicates = 0;
        $droppedNoAnchor = 0;

        foreach ($blocks as $block) {
            $names = $this->extractNamesByLocale($block, $locales);

            if (!isset($names[$anchorLocale])) {
                $droppedNoAnchor++;
                continue;
            }

            $anchorName = $this->normalizeName($names[$anchorLocale]);
            // Reject taxonomy build-error markers (the upstream file has a
            // handful of literal "ERROR - ..." lines from its own tooling)
            // and anything too long to plausibly be a single ingredient name
            // rather than a sentence.
            if ($anchorName === '' || mb_strlen($anchorName) < 2 || mb_strlen($anchorName) > 100) {
                continue;
            }
            if (stripos($anchorName, 'error') === 0) {
                continue;
            }

            $code = mb_substr($this->slugify($anchorName), 0, 150);
            if ($code === '' || isset($seenCodes[$code])) {
                $droppedDuplicates++;
                continue;
            }
            $seenCodes[$code] = true;

            $row = ['code' => $code];
            foreach ($locales as $locale) {
                $row[$locale] = isset($names[$locale]) ? $this->normalizeName($names[$locale]) : '';
            }
            $entries[] = $row;
        }

        usort($entries, static fn (array $a, array $b) => $a['code'] <=> $b['code']);

        $outputPath = str_starts_with($input->getOption('output'), '/')
            ? $input->getOption('output')
            : $this->projectDir . '/' . $input->getOption('output');

        $handle = fopen($outputPath, 'w');
        fputcsv($handle, ['code', ...$locales]);
        foreach ($entries as $row) {
            fputcsv($handle, [$row['code'], ...array_map(fn ($l) => $row[$l], $locales)]);
        }
        fclose($handle);

        $io->success(sprintf(
            'Wrote %d ingredients to %s (dropped %d without a "%s" name, %d duplicate slugs).',
            count($entries),
            $outputPath,
            $droppedNoAnchor,
            $anchorLocale,
            $droppedDuplicates
        ));

        foreach ($locales as $locale) {
            $withLocale = count(array_filter($entries, static fn ($r) => $r[$locale] !== ''));
            $io->writeln(sprintf('  %s: %d / %d', $locale, $withLocale, count($entries)));
        }

        return Command::SUCCESS;
    }

    /**
     * Parses one taxonomy block and returns the canonical (first) name for
     * each requested locale it defines. Only plain "xx: value, synonym, ..."
     * lines are read — "synonyms:xx:" blocks (generic word synonyms, not
     * ingredients) and property lines (e.g. "wikidata:en:...", which always
     * have a second colon) are ignored.
     *
     * @param string[] $locales
     * @return array<string, string>
     */
    private function extractNamesByLocale(string $block, array $locales): array
    {
        $wanted = array_flip($locales);
        $names = [];

        foreach (explode("\n", trim($block)) as $line) {
            $line = rtrim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '<') {
                continue;
            }
            if (str_starts_with($line, 'synonyms:') || str_starts_with($line, 'stopwords:')) {
                continue;
            }
            if (preg_match('/^([a-z]{2,3}(?:-[a-z]{2,4})?): (.+)$/', $line, $m) && isset($wanted[$m[1]])) {
                $first = explode(',', $m[2], 2)[0];
                $names[$m[1]] = trim($first);
            }
        }

        return $names;
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/', ' ', trim($name));
        if ($name === '') {
            return '';
        }

        $firstChar = mb_substr($name, 0, 1, 'UTF-8');
        $rest      = mb_substr($name, 1, null, 'UTF-8');

        return mb_strtoupper($firstChar, 'UTF-8') . $rest;
    }

    private function slugify(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $ascii = $ascii !== false ? $ascii : $name;
        $slug  = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii));

        return trim($slug, '-');
    }
}
