<?php

namespace App\Service\Upload;

/**
 * Seam for visual content moderation (e.g. flagging sexually explicit
 * images) of a file that has already passed UploadValidator's MIME/size/
 * dimension checks. Deliberately an interface: the incident that motivated
 * UploadValidator was an explicit image uploaded as a restaurant logo, and
 * closing that gap for real requires an actual image classifier or a cloud
 * "SafeSearch"-style API — not a heuristic improvised here (pixel/color
 * histograms, skin-tone ratios, etc. are not reliable content moderation
 * and must not be used as a substitute).
 *
 * Today's binding is NullContentModeration, which always allows — it exists
 * only so UploadValidator has something to call. Wiring a real classifier
 * is future work: implement this interface against whatever service is
 * chosen and swap the alias in config/services.yaml. No other change is
 * needed — UploadValidator already calls this hook on every validated
 * upload and treats a rejection as a normal validation failure.
 */
interface ContentModerationInterface
{
    public function moderate(string $filePath, string $mimeType): ContentModerationResult;
}
