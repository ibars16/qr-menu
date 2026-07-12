<?php

namespace App\Command;

use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use App\Repository\GlobalIngredientRepository;
use App\Service\GlobalIngredientTranslationBackfiller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads config/global_ingredients.csv (see GenerateGlobalIngredientsDatasetCommand)
 * into the global_ingredient / global_ingredient_translation tables.
 *
 * Upserts by "code", so it's always safe to re-run — e.g. after regenerating
 * the CSV from a fresher upstream taxonomy, after hand-editing a row, or
 * after adding a new locale column for a language this app didn't support
 * before.
 *
 * After the CSV is loaded, also backfills any locale the CSV left blank for
 * a given ingredient (e.g. the source dataset has no Spanish name) using AI
 * translation — see GlobalIngredientTranslationBackfiller. This only ever
 * runs here, at import time; the generated translation is persisted once
 * and served like any other translation from then on — nothing is ever
 * translated on a request path.
 */
#[AsCommand(
    name: 'app:global-ingredients:import',
    description: 'Imports config/global_ingredients.csv into the Global Ingredient Library, AI-backfilling any locale the CSV left blank',
)]
final class ImportGlobalIngredientsCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GlobalIngredientRepository $repository,
        private readonly GlobalIngredientTranslationBackfiller $backfiller,
        private readonly string $projectDir,
        private readonly string $geminiApiKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the CSV to import', 'config/global_ingredients.csv')
            ->addOption('ai-source-locale', null, InputOption::VALUE_REQUIRED, 'Locale to translate FROM for AI backfill', 'en')
            ->addOption('ai-target-locales', null, InputOption::VALUE_REQUIRED, 'Comma-separated locales to AI-backfill wherever the CSV left them blank', 'es')
            ->addOption('skip-ai-backfill', null, InputOption::VALUE_NONE, 'Only load the CSV; skip the AI translation backfill step');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Doctrine's dev-env debug middleware retains every query/backtrace
        // for the profiler, which otherwise exhausts PHP's default CLI
        // memory limit well before ~5k rows are through, regardless of the
        // batched flush()/clear() below.
        ini_set('memory_limit', '-1');

        $path = $input->getOption('file');
        if (!str_starts_with($path, '/')) {
            $path = $this->projectDir . '/' . $path;
        }
        if (!is_readable($path)) {
            $io->error("Cannot read CSV: {$path}");
            return Command::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '\\');
        if ($header === false || $header[0] !== 'code') {
            $io->error('CSV must have a "code" column as its first column.');
            return Command::FAILURE;
        }
        $locales = array_slice($header, 1);

        $created = 0;
        $updated = 0;
        $translationsWritten = 0;
        $row = 0;

        while (($cols = fgetcsv($handle, escape: '\\')) !== false) {
            $row++;
            $data = array_combine($header, $cols);
            $code = trim($data['code']);
            if ($code === '') {
                continue;
            }

            $ingredient = $this->repository->findOneBy(['code' => $code]);
            if ($ingredient) {
                $updated++;
            } else {
                $ingredient = new GlobalIngredient();
                $ingredient->setCode($code);
                $this->em->persist($ingredient);
                $created++;
            }

            foreach ($locales as $locale) {
                $name = trim($data[$locale] ?? '');
                if ($name === '') {
                    continue;
                }

                $translation = $ingredient->getTranslation($locale);
                if (!$translation) {
                    $translation = new GlobalIngredientTranslation();
                    $translation->setLocale($locale);
                    $ingredient->addTranslation($translation);
                    $this->em->persist($translation);
                }
                $translation->setName($name);
                $translationsWritten++;
            }

            if ($row % self::BATCH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
                $io->write('.');
            }
        }
        fclose($handle);

        $this->em->flush();
        $io->newLine();

        $io->success(sprintf(
            '%d created, %d updated, %d translations written (locales: %s).',
            $created,
            $updated,
            $translationsWritten,
            implode(', ', $locales)
        ));

        if ($input->getOption('skip-ai-backfill')) {
            return Command::SUCCESS;
        }

        $sourceLocale  = $input->getOption('ai-source-locale');
        $targetLocales = array_filter(array_map('trim', explode(',', $input->getOption('ai-target-locales'))));

        if (empty($this->geminiApiKey) || $this->geminiApiKey === 'your-gemini-api-key-here') {
            $io->warning('GEMINI_API_KEY is not configured — skipping AI translation backfill. Ingredients without a CSV translation will stay untranslated until this command is re-run with a key set.');
            return Command::SUCCESS;
        }

        foreach ($targetLocales as $targetLocale) {
            if ($targetLocale === $sourceLocale) {
                continue;
            }

            $io->section(sprintf('AI backfill: %s → %s', $sourceLocale, $targetLocale));
            $result = $this->backfiller->backfill($targetLocale, $sourceLocale, function () use ($io) {
                $io->write('.');
            });
            $io->newLine();
            $io->writeln(sprintf(
                '  %d translated, %d failed, %d skipped (no %s source name).',
                $result['translated'],
                $result['failed'],
                $result['skippedNoSource'],
                $sourceLocale
            ));
        }

        return Command::SUCCESS;
    }
}
