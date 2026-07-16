<?php

namespace App\Command;

use App\Entity\MenuImportBatch;
use App\Service\MenuImportPipeline;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The full pipeline (extraction for every page, then assembly) for one
 * batch, in a single command. This is what ProcessSpawningBatchProcessingTrigger
 * spawns automatically after every upload — it's no longer something a user
 * needs to run by hand, but it's kept as a real command (not folded away)
 * since it's still the right tool for manually re-running a stuck or failed
 * batch. AnalyzeMenuImportPageCommand and AssembleMenuImportBatchCommand
 * still exist too, for debugging a single page or re-running just assembly.
 */
#[AsCommand(
    name: 'app:menu-import:process-batch',
    description: 'Runs the full menu-import pipeline (extraction + assembly) for one batch.',
)]
final class ProcessMenuImportBatchCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MenuImportPipeline $pipeline,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('batchId', InputArgument::REQUIRED, 'The MenuImportBatch id to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchId = (int) $input->getArgument('batchId');

        $batch = $this->em->getRepository(MenuImportBatch::class)->find($batchId);
        if (!$batch) {
            $io->error("MenuImportBatch #{$batchId} not found.");
            return Command::FAILURE;
        }

        $this->pipeline->processBatch($batch);

        $io->writeln('Final batch status: ' . $batch->getStatus()->value);

        return Command::SUCCESS;
    }
}
