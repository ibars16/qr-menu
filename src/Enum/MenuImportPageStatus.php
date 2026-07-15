<?php

namespace App\Enum;

/**
 * Lifecycle of one uploaded page (photo). Only PENDING is reachable from
 * Phase 1 — ANALYZING/EXTRACTED/FAILED are written by Phase 2's extraction
 * step, which does not exist yet.
 */
enum MenuImportPageStatus: string
{
    case PENDING = 'pending';
    case ANALYZING = 'analyzing';
    case EXTRACTED = 'extracted';
    case FAILED = 'failed';
}
