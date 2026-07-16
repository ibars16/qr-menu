<?php

namespace App\Service;

use App\Entity\MenuImportBatch;
use App\Enum\MenuImportBatchStatus;
use App\Enum\MenuImportPageStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The full pipeline for one batch: extract every pending page, then
 * assemble. This is the exact unit of work a future Messenger handler would
 * wrap and call directly — see BatchProcessingTriggerInterface for how it's
 * invoked today (a spawned background process) versus how it would be
 * invoked with a real queue (a message dispatch). This class itself doesn't
 * know or care which.
 */
final class MenuImportPipeline
{
    public function __construct(
        private readonly MenuImportExtractionService $extractionService,
        private readonly MenuImportAssembler $assembler,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function processBatch(MenuImportBatch $batch): void
    {
        // Idempotency guard: if this batch already reached a terminal state
        // (e.g. the manual command is re-run by hand for debugging, or the
        // trigger somehow fires twice), do nothing rather than risk
        // re-running assembly and duplicating categories/products.
        if (in_array($batch->getStatus(), [MenuImportBatchStatus::READY_FOR_REVIEW, MenuImportBatchStatus::COMPLETED, MenuImportBatchStatus::FAILED], true)) {
            return;
        }

        $batch->setStatus(MenuImportBatchStatus::PROCESSING);
        $this->em->flush();

        foreach ($batch->getPages() as $page) {
            if ($page->getStatus() !== MenuImportPageStatus::PENDING) {
                continue;
            }

            try {
                $this->extractionService->analyzePage($page);
            } catch (\Throwable $e) {
                // One page's unexpected crash (as opposed to an ordinary
                // AIProviderException, which analyzePage() already handles
                // internally) shouldn't stop the rest of the batch.
                $this->logger->error('Unexpected error analyzing menu import page', ['pageId' => $page->getId(), 'exception' => $e]);
                $page->setStatus(MenuImportPageStatus::FAILED);
                $page->setErrorMessage('Unexpected error: ' . $e->getMessage());
                $this->em->flush();
            }
        }

        try {
            $this->assembler->assemble($batch);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error assembling menu import batch', ['batchId' => $batch->getId(), 'exception' => $e]);
            $batch->setStatus(MenuImportBatchStatus::FAILED);
            $batch->setErrorMessage('Unexpected error during assembly: ' . $e->getMessage());
            $this->em->flush();
        }
    }
}
