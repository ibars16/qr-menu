<?php

namespace App\Service\Upload;

/**
 * Non-fatal quality issues UploadValidator can flag — cosmetic/UX concerns,
 * never security. Unlike UploadValidationError, a warning never blocks an
 * upload on its own: the caller shows it to the uploader and lets them
 * confirm "upload anyway" (see UploadValidator::validate()'s
 * $qualityWarningsConfirmed parameter). Only enabled for profiles that opt
 * in via LIMITS['qualityWarningsEnabled'] — today, hero_image only.
 */
enum UploadQualityWarning
{
    /** Below the profile's own recommended minimum, but not unusably tiny. */
    case DimensionsBelowRecommended;

    /** Taller than wide — likely to have its sides cropped hard by a wide, short band. */
    case PortraitAspectRatio;
}
