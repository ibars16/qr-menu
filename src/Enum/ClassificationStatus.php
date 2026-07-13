<?php

namespace App\Enum;

/**
 * Lifecycle of one proposed (subject, label) classification — see
 * ClassificationLog and ClassificationRunner.
 */
enum ClassificationStatus: string
{
    /** The AI confidently found no applicable label for this subject — a sentinel row (label = null) so it isn't re-submitted every run. */
    case NO_LABELS_FOUND = 'no_labels_found';

    /** Confidence met the auto-apply threshold — already persisted into the real domain table. */
    case AUTO_APPLIED = 'auto_applied';

    /** Below the auto-apply threshold (or --review-only was used) — waiting in the review queue. */
    case PENDING_REVIEW = 'pending_review';

    /** A human approved this via the import command — persisted into the real domain table. */
    case APPROVED = 'approved';

    /** A human rejected this via the import command — never persisted. */
    case REJECTED = 'rejected';
}
