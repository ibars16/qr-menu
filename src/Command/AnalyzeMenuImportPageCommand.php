<?php

namespace App\Command;

use App\Entity\MenuImportPage;
use App\Service\MenuImportExtractionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual trigger for Phase 2 extraction — real and permanent, not a
 * throwaway. Menu-import doesn't dispatch this automatically yet (see the
 * architecture plan: the Messenger worker question is deliberately deferred
 * past Phase 1/2), so this is how a page actually gets analyzed for now.
 */
#[AsCommand(
    name: 'app:menu-import:analyze-page',
    description: 'Sends one already-uploaded MenuImportPage to a vision provider and stores the raw extracted JSON. Creates no Product/Category/Ingredient rows.',
)]
final class AnalyzeMenuImportPageCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MenuImportExtractionService $extractionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pageId', InputArgument::REQUIRED, 'The MenuImportPage id to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageId = (int) $input->getArgument('pageId');

        $page = $this->em->getRepository(MenuImportPage::class)->find($pageId);
        if (!$page) {
            $io->error("MenuImportPage #{$pageId} not found.");
            return Command::FAILURE;
        }

        $io->writeln("Analyzing page #{$pageId} ({$page->getImagePath()})...");
        $this->extractionService->analyzePage($page);

        $io->writeln('Status: ' . $page->getStatus()->value);

        if ($page->getStatus()->value === 'failed') {
            $io->error($page->getErrorMessage() ?? 'Unknown failure.');
            return Command::FAILURE;
        }

        $io->writeln('Detected language: ' . ($page->getDetectedLocale() ?? '(none)'));
        $io->section('Extracted data');
        $io->writeln(json_encode($page->getExtractedData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
