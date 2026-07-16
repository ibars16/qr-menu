<?php

namespace App\Service;

use App\Entity\MenuImportBatch;

/**
 * The one seam between "a batch was just uploaded" and "processing actually
 * happens". Deliberately abstracted behind an interface so the underlying
 * mechanism can change without touching the controller or MenuImportPipeline
 * itself — today's implementation (ProcessSpawningBatchProcessingTrigger)
 * spawns a detached background process because no Messenger worker is
 * configured in this deployment yet; swapping to a real queue later means
 * writing one new class that dispatches a message instead, and changing the
 * alias in config/services.yaml. Nothing else changes.
 */
interface BatchProcessingTriggerInterface
{
    public function trigger(MenuImportBatch $batch): void;
}
