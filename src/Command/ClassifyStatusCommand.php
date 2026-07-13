<?php

namespace App\Command;

use App\Repository\ClassificationLogRepository;
use App\Service\Classification\ClassificationTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:classify:status',
    description: 'Shows classification progress for one or all registered tasks',
)]
final class ClassifyStatusCommand extends Command
{
    public function __construct(
        private readonly ClassificationTaskRegistry $registry,
        private readonly ClassificationLogRepository $logRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('task', InputArgument::OPTIONAL, 'Show just this task; omit to list every registered task');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $requested = $input->getArgument('task');
        $names = $requested ? [$requested] : $this->registry->getNames();

        if (empty($names)) {
            $io->warning('No classification tasks registered.');
            return Command::SUCCESS;
        }

        foreach ($names as $name) {
            $task = $this->registry->get($name);
            $counts = $this->logRepository->countByStatus($name);

            $io->section($name);
            $io->table(
                ['Status', 'Count'],
                array_map(static fn ($status, $count) => [$status, $count], array_keys($counts), array_values($counts))
                    ?: [['(none yet)', 0]]
            );
            $io->writeln(sprintf('Still unclassified: <info>%d</info>', $task->countUnclassified()));
            $io->newLine();
        }

        return Command::SUCCESS;
    }
}
