<?php

namespace App\Command;

use App\Entity\MenuImportBatch;
use App\Service\MenuImportAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual trigger for Phase 3 assembly — real and permanent, same reasoning
 * as AnalyzeMenuImportPageCommand: the Messenger worker question is still
 * deliberately deferred, so this is how a batch actually gets assembled for
 * now. Everything it creates stays active=false, needsReview=true — see
 * MenuImportAssembler's class docblock.
 */
#[AsCommand(
    name: 'app:menu-import:assemble-batch',
    description: 'Builds real (inactive, needs-review) Category/Product/Ingredient rows from a batch\'s already-extracted pages. Never publishes anything.',
)]
final class AssembleMenuImportBatchCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MenuImportAssembler $assembler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('batchId', InputArgument::REQUIRED, 'The MenuImportBatch id to assemble');
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

        $result = $this->assembler->assemble($batch);

        $io->writeln('Batch status: ' . $batch->getStatus()->value);
        $io->table(
            ['Metric', 'Count'],
            [
                ['Categories created', $result->categoriesCreated],
                ['Categories reused', $result->categoriesReused],
                ['Products created', $result->productsCreated],
                ['Products skipped (no name)', $result->productsSkipped],
                ['Ingredients linked', $result->ingredientsLinked],
                ['Ingredients skipped (uncertain)', $result->ingredientsSkippedUncertain],
                ['Tags assigned', $result->tagsAssigned],
            ]
        );
        $io->note('Everything created is active=false, needsReview=true — invisible to the public menu and Smart Waiter until a later review step confirms it.');

        return Command::SUCCESS;
    }
}
