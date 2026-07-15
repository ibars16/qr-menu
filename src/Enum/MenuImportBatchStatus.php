<?php

namespace App\Enum;

/**
 * Lifecycle of one upload session. Only UPLOADED is reachable from Phase 1
 * (storage only, zero AI) — the rest are written by later phases:
 * PROCESSING/READY_FOR_REVIEW/FAILED by extraction+assembly (Phase 2/3),
 * COMPLETED once every drafted item from the batch is confirmed or
 * discarded (Phase 4).
 */
enum MenuImportBatchStatus: string
{
    case UPLOADED = 'uploaded';
    case PROCESSING = 'processing';
    case READY_FOR_REVIEW = 'ready_for_review';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
