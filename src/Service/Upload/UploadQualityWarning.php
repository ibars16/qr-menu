<?php

namespace App\Service\Upload;

/**
 * Non-fatal quality issues UploadValidator can flag — cosmetic/UX concerns,
 * never security. Unlike UploadValidationError, a warning never blocks an
 * upload on its own: most are shown to the uploader, who confirms "upload
 * anyway" (see UploadValidator::validate()'s $qualityWarningsConfirmed
 * parameter); a purely informative one (requiresConfirmation() false) lets
 * the upload through and is only reported after saving. Only enabled for
 * profiles that opt in via LIMITS['qualityWarningsEnabled'] — today,
 * hero_image only.
 */
enum UploadQualityWarning
{
    /** Below the profile's own recommended minimum, but not unusably tiny. */
    case DimensionsBelowRecommended;

    /** Taller than wide — likely to have its sides cropped hard by a wide, short band. */
    case PortraitAspectRatio;

    /**
     * Clears the minimum but is narrower than the profile's recommended
     * width (LIMITS['recommendedWidth']) — fine on a phone, soft on a large
     * screen. Informative only: never asks for confirmation.
     */
    case WidthBelowRecommended;

    public function requiresConfirmation(): bool
    {
        return $this !== self::WidthBelowRecommended;
    }
}
