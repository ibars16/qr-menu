<?php

namespace App\Command;

use App\Enum\ClassificationStatus;
use App\Repository\ClassificationLogRepository;
use App\Service\Classification\ClassificationReviewFileService;
use App\Service\Classification\ClassificationTaskRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies human decisions from a reviewed export file — the only place a
 * below-threshold (or --review-only) proposal ever gets permanently
 * persisted. Safe to re-run against the same file: a row already resolved
 * (approved/rejected/auto-applied) is left untouched, and a blank decision
 * is simply skipped, leaving it pending for a later pass.
 */
#[AsCommand(
    name: 'app:classify:import',
    description: 'Applies approve/reject decisions from a reviewed classification export file',
)]
final class ClassifyImportCommand extends Command
{
    public function __construct(
        private readonly ClassificationTaskRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly ClassificationLogRepository $logRepository,
        private readonly ClassificationReviewFileService $reviewFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::REQUIRED, 'Registered classification task name')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the reviewed .csv or .json export');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $task = $this->registry->get($input->getArgument('task'));
        $file = $input->getArgument('file');

        try {
            $decisions = $this->reviewFile->readDecisions($file);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (empty($decisions)) {
            $io->warning('No rows found in ' . $file . '.');
            return Command::SUCCESS;
        }

        $approved = 0;
        $rejected = 0;
        $skippedBlank = 0;
        $skippedAlreadyResolved = 0;
        $notFound = 0;

        foreach ($decisions as $logId => $decision) {
            $log = $this->logRepository->find($logId);
            if (!$log || $log->getClassificationType() !== $task->getName()) {
                $notFound++;
                continue;
            }

            if ($log->getStatus() !== ClassificationStatus::PENDING_REVIEW) {
                $skippedAlreadyResolved++;
                continue;
            }

            if ($decision['decision'] === 'approve') {
                $subject = $task->getSubjectById($log->getSubjectId());
                if ($subject && $log->getLabel() !== null) {
                    $task->apply($subject, $log->getLabel(), $log->getAttributes());
                }
                $log->setStatus(ClassificationStatus::APPROVED);
                if ($decision['notes']) {
                    $log->setReviewNote($decision['notes']);
                }
                $approved++;
            } elseif ($decision['decision'] === 'reject') {
                $log->setStatus(ClassificationStatus::REJECTED);
                if ($decision['notes']) {
                    $log->setReviewNote($decision['notes']);
                }
                $rejected++;
            } else {
                $skippedBlank++;
            }
        }

        $this->em->flush();

        $io->table(['Outcome', 'Count'], [
            ['Approved & applied', $approved],
            ['Rejected', $rejected],
            ['Left blank (still pending)', $skippedBlank],
            ['Already resolved previously', $skippedAlreadyResolved],
            ['Not found / wrong task', $notFound],
        ]);
        $io->success('Import complete.');

        return Command::SUCCESS;
    }
}
