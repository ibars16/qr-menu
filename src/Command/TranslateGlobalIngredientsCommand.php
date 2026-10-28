<?php

namespace App\Command;

use App\Repository\GlobalIngredientRepository;
use App\Service\AI\AIProviderFactory;
use App\Service\GlobalIngredientLibraryTranslation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daily batch: AI-translates the Global Ingredient Library's English names
 * into the locales it's missing — see GlobalIngredientLibraryTranslation.
 * Groq's free tier only, so run it once a day until --status shows nothing
 * left: each run stops on its own at the daily quota and the next one
 * resumes where it stopped. Never overwrites an existing name.
 */
#[AsCommand(
    name: 'app:global-ingredients:translate',
    description: 'AI-translates missing Global Ingredient Library names with Groq (two passes), stopping at the daily quota',
)]
final class TranslateGlobalIngredientsCommand extends Command
{
    private const DEFAULT_LOCALES = 'fr,it,de,pt,es';

    public function __construct(
        private readonly GlobalIngredientLibraryTranslation $translation,
        private readonly GlobalIngredientRepository $repository,
        private readonly AIProviderFactory $providerFactory,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('locales', null, InputOption::VALUE_REQUIRED, 'Comma-separated locales to fill in', self::DEFAULT_LOCALES)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max names this run (default: until the daily quota)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Names per AI call', 40)
            ->addOption('code', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this ingredient code (repeatable)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Call the AI and show the results, but write nothing')
            ->addOption('status', null, InputOption::VALUE_NONE, 'No AI: only show how many names each locale is missing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $languages = require $this->projectDir . '/config/languages.php';
        $locales = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $input->getOption('locales'))))));
        foreach ($locales as $locale) {
            if ($locale === 'en' || !isset($languages[$locale]) || !preg_match('/^[a-z]{2}$/', $locale)) {
                $io->error("\"{$locale}\" isn't a locale this command can fill in (en is the source; see config/languages.php).");
                return Command::FAILURE;
            }
        }

        $before = $this->repository->countMissingTranslations($locales);
        $io->title('Global ingredient names still missing');
        $io->table(['locale', 'missing'], array_map(static fn ($locale, $n) => [$locale, $n], array_keys($before), $before));
        if ($input->getOption('status')) {
            return Command::SUCCESS;
        }
        if (array_sum($before) === 0) {
            $io->success('Nothing left to translate.');
            return Command::SUCCESS;
        }

        if ($this->providerFactory->getProviderById('groq-llama') === null) {
            $io->error('groq-llama is not configured (GROQ_API_KEY) — this command uses nothing else.');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $codes = $input->getOption('code') !== [] ? $input->getOption('code') : null;

        $io->section($dryRun ? 'Translating (dry run — nothing is written)' : 'Translating');
        $result = $this->translation->run(
            $locales,
            max(1, (int) $input->getOption('batch-size')),
            $limit,
            $codes,
            $dryRun,
            static fn (string $locale, int $written, int $failed) => $io->writeln(sprintf('  %s: %d written, %d left for the next run', $locale, $written, $failed)),
        );

        if ($dryRun || $output->isVerbose()) {
            $io->table(['locale', 'en', 'translation'], array_map(static fn (array $s) => [$s['locale'], $s['en'], $s['name']], $result['samples']));
        }

        $after = $this->repository->countMissingTranslations($locales);
        $io->writeln(sprintf(
            '%d name(s) %s, %d left for the next run, %d Groq token(s) used. Still missing: %d (%s).',
            $result['written'],
            $dryRun ? 'translated (not written)' : 'written',
            $result['failed'],
            $result['tokens'],
            array_sum($after),
            implode(', ', array_map(static fn ($locale, $n) => "{$locale} {$n}", array_keys($after), $after)),
        ));

        return match ($result['stopped']) {
            'quota' => $this->stoppedAtQuota($io),
            'errors' => $this->stoppedOnErrors($io),
            default => $this->finished($io, array_sum($after)),
        };
    }

    private function stoppedAtQuota(SymfonyStyle $io): int
    {
        $io->note('Stopped at Groq\'s daily quota. Run the same command again tomorrow — it resumes where it stopped.');

        return Command::SUCCESS;
    }

    private function stoppedOnErrors(SymfonyStyle $io): int
    {
        $io->error('Stopped after several failed batches in a row (not the quota) — see var/log. Nothing was overwritten; re-run to resume.');

        return Command::FAILURE;
    }

    private function finished(SymfonyStyle $io, int $stillMissing): int
    {
        $io->success($stillMissing === 0 ? 'The library is fully translated.' : 'Run finished.');

        return Command::SUCCESS;
    }
}
