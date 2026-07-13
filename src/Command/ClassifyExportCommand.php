<?php

namespace App\Command;

use App\Enum\ClassificationStatus;
use App\Repository\ClassificationLogRepository;
use App\Service\Classification\ClassificationReviewFileService;
use App\Service\Classification\ClassificationTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:classify:export',
    description: 'Exports pending classification proposals to a CSV/JSON file for manual review',
)]
final class ClassifyExportCommand extends Command
{
    public function __construct(
        private readonly ClassificationTaskRegistry $registry,
        private readonly ClassificationLogRepository $logRepository,
        private readonly ClassificationReviewFileService $reviewFile,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::REQUIRED, 'Registered classification task name')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Which status to export', ClassificationStatus::PENDING_REVIEW->value)
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output file path (.csv or .json); defaults under var/classification/')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'csv or json — only used when --output is omitted', 'csv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $task = $this->registry->get($input->getArgument('task'));

        $status = ClassificationStatus::tryFrom($input->getOption('status'));
        if (!$status) {
            $io->error('Unknown --status. Valid values: ' . implode(', ', array_map(fn ($c) => $c->value, ClassificationStatus::cases())));
            return Command::FAILURE;
        }

        $logs = $this->logRepository->findByStatus($task->getName(), $status);
        if (empty($logs)) {
            $io->success(sprintf('Nothing to export — no "%s" rows for "%s".', $status->value, $task->getName()));
            return Command::SUCCESS;
        }

        $format = $input->getOption('format') === 'json' ? 'json' : 'csv';
        $path = $input->getOption('output')
            ?? sprintf('%s/var/classification/%s-%s.%s', $this->projectDir, $task->getName(), $status->value, $format);

        @mkdir(dirname($path), 0777, true);
        $count = $this->reviewFile->export($logs, $task, $path);

        $io->success(sprintf('Exported %d row(s) to %s', $count, $path));
        $io->note(sprintf(
            "Fill in the \"decision\" column (approve / reject) for the rows you've reviewed, then run:\nphp bin/console app:classify:import %s %s",
            $task->getName(),
            $path
        ));

        return Command::SUCCESS;
    }
}
