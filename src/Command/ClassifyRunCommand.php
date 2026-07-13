<?php

namespace App\Command;

use App\Service\Classification\ClassificationRunner;
use App\Service\Classification\ClassificationTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually triggers one AI-assisted classification pass — see
 * App\Service\Classification. Never called from a request path.
 *
 * Proposals at or above --threshold are persisted immediately (unless
 * --review-only); everything else is logged as pending_review for
 * classify:export / classify:import. A subject the AI is genuinely unsure
 * about (as opposed to confidently finding nothing) gets no log row at all
 * and simply stays eligible for the next run.
 */
#[AsCommand(
    name: 'app:classify:run',
    description: 'Runs one AI-assisted classification pass for a registered task (e.g. global_ingredient_allergens)',
)]
final class ClassifyRunCommand extends Command
{
    public function __construct(
        private readonly ClassificationTaskRegistry $registry,
        private readonly ClassificationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::REQUIRED, 'Registered classification task name — see app:classify:status for the list')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max subjects to consider this run (cost/quota control)', 200)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Subjects sent to the AI per call', 40)
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Confidence (0-1) at/above which a proposal is auto-applied', 0.9)
            ->addOption('review-only', null, InputOption::VALUE_NONE, 'Never auto-apply — route every proposal to the review queue regardless of confidence');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $task = $this->registry->get($input->getArgument('task'));
        $limit = max(1, (int) $input->getOption('limit'));
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $threshold = (float) $input->getOption('threshold');
        $reviewOnly = (bool) $input->getOption('review-only');

        if ($threshold < 0 || $threshold > 1) {
            $io->error('--threshold must be between 0 and 1 (e.g. 0.9 for 90%).');
            return Command::FAILURE;
        }

        $io->title(sprintf(
            'Classifying "%s" — up to %d subject(s), threshold %d%%%s',
            $task->getName(),
            $limit,
            (int) round($threshold * 100),
            $reviewOnly ? ', review-only (nothing auto-applied)' : ''
        ));

        $result = $this->runner->run($task, $limit, $batchSize, $threshold, $reviewOnly, function (int $batchCount, ?\Throwable $error) use ($io) {
            if ($error) {
                $io->writeln(sprintf('  <error>Batch of %d failed: %s</error>', $batchCount, $error->getMessage()));
            } else {
                $io->write('.');
            }
        });

        $io->newLine(2);
        $io->table(['Outcome', 'Count'], [
            ['Subjects considered', $result['totalSubjects']],
            ['Auto-applied', $result['autoApplied']],
            ['Sent to review', $result['pendingReview']],
            ['Confidently no match', $result['noLabelsFound']],
            ['Left unclassified (uncertain)', $result['uncertainSkipped']],
            ['Discarded (invalid label)', $result['discardedInvalid']],
            ['Batches failed', $result['batchesFailed']],
        ]);

        if ($result['totalSubjects'] === 0) {
            $io->success('Nothing left to classify.');
        } else {
            $io->success('Run complete.');
        }

        if ($result['pendingReview'] > 0) {
            $io->note(sprintf(
                'Run "php bin/console app:classify:export %s" to review the %d proposal(s) sent to the queue.',
                $task->getName(),
                $result['pendingReview']
            ));
        }

        return Command::SUCCESS;
    }
}
