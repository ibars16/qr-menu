<?php

namespace App\Service\Upload;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Single point of validation for every image upload in the app (restaurant
 * logo, dish/hero photos, menu-import pages, ...). Written after an
 * explicit image was uploaded as a restaurant logo and served straight
 * back out of public/uploads/logos/ — the logo endpoint had no MIME, size,
 * or dimension checks at all. Callers MUST call validate() before moving
 * an UploadedFile anywhere, and must move it using the safeFilename this
 * returns rather than anything derived from the client's filename.
 */
final class UploadValidator
{
    private const ALLOWED_EXTENSIONS_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * checkDimensions is off for menu-import pages on purpose: those photos
     * of a printed menu are read with getimagesize() only when a min/max is
     * actually enforced, so a 30-file batch at up to 15MB each never has to
     * decode image dimensions for files where no dimension rule applies.
     */
    private const LIMITS = [
        'logo' => [
            'maxSizeBytes'    => 2 * 1024 * 1024,
            'checkDimensions' => true,
            'minWidth'        => 100,
            'minHeight'       => 100,
            'maxWidth'        => 2000,
            'maxHeight'       => 2000,
        ],
        'dish_image' => [
            'maxSizeBytes'    => 8 * 1024 * 1024,
            'checkDimensions' => true,
            'minWidth'        => 400,
            'minHeight'       => 400,
            'maxWidth'        => 5000,
            'maxHeight'       => 5000,
        ],
        /**
         * Own profile, deliberately separate from dish_image even though it
         * used to just borrow it: a hero band is a wide, short strip — the
         * upload just needs to not be tiny, not clear the same bar as a
         * dish photo meant to be cropped square/tall too. Lower minimum,
         * same size/max-dimension ceiling otherwise. Whoever uploads this is
         * the restaurant owner, not a designer — 300px is "not a thumbnail",
         * not a print-quality bar.
         *
         * qualityWarningsEnabled: the only profile, for now, where being
         * below minWidth/minHeight or portrait-oriented is a non-fatal
         * *warning* (see validate()) rather than an outright rejection —
         * a hero photo is décor, not a menu item people order off of or a
         * brand mark that has to render crisply everywhere, so a slightly
         * soft or oddly-cropped one is the owner's call, not ours to block.
         * dish_image and logo don't opt in: keeping dish photos sharp is a
         * real commercial concern (a blurry photo of the food itself hurts
         * the product), and a logo's constraints are about how the mark
         * actually renders, not friendliness — neither shares the reasoning
         * that motivated relaxing this one.
         */
        'hero_image' => [
            'maxSizeBytes'          => 8 * 1024 * 1024,
            'checkDimensions'       => true,
            'minWidth'              => 300,
            'minHeight'             => 300,
            'maxWidth'              => 5000,
            'maxHeight'             => 5000,
            'qualityWarningsEnabled' => true,
        ],
        'menu_import_page' => [
            'maxSizeBytes'    => 15 * 1024 * 1024,
            'checkDimensions' => false,
        ],
    ];

    public function __construct(
        private readonly ContentModerationInterface $moderation,
    ) {
    }

    /**
     * The minimum width/height a profile's dimension check enforces (they're
     * always equal — every profile here checks a square-ish floor, not an
     * aspect ratio) — for callers building a "too small" error message that
     * tells the person what number would actually pass, instead of hard-
     * coding it in translation strings that would silently drift out of
     * sync with this class.
     */
    public static function minDimension(UploadProfile $profile): int
    {
        return self::LIMITS[$profile->value]['minWidth'];
    }

    /**
     * $qualityWarningsConfirmed: the uploader has already seen this
     * profile's non-fatal quality warnings (see UploadQualityWarning) and
     * chose to continue anyway. It ONLY ever affects whether a quality
     * warning blocks the upload — every check above the warnings section
     * below (file validity, real MIME, max size, max dimensions) and the
     * content-moderation check after it are security-relevant and run
     * completely unconditionally, with their own `return failure(...)`
     * before this parameter is ever read. There is no code path by which
     * this flag can suppress any of those — a caller cannot construct one
     * by passing true, only skip the *needsConfirmation* branch below.
     */
    public function validate(UploadedFile $file, UploadProfile $profile, bool $qualityWarningsConfirmed = false): UploadValidationResult
    {
        if (!$file->isValid()) {
            return UploadValidationResult::failure(UploadValidationError::InvalidFile);
        }

        $limits = self::LIMITS[$profile->value];

        // Real content-sniffed MIME type (finfo over the file's actual
        // bytes), never the client-supplied filename or Content-Type header.
        $mimeType = $file->getMimeType();
        if (!isset(self::ALLOWED_EXTENSIONS_BY_MIME[$mimeType])) {
            return UploadValidationResult::failure(UploadValidationError::UnsupportedType);
        }

        if ($file->getSize() > $limits['maxSizeBytes']) {
            return UploadValidationResult::failure(UploadValidationError::TooLarge);
        }

        $warnings = [];

        if ($limits['checkDimensions']) {
            $dimensions = @getimagesize($file->getPathname());
            if ($dimensions === false) {
                return UploadValidationResult::failure(UploadValidationError::UnsupportedType);
            }

            [$width, $height] = $dimensions;
            $qualityWarningsEnabled = $limits['qualityWarningsEnabled'] ?? false;

            if ($width < $limits['minWidth'] || $height < $limits['minHeight']) {
                if ($qualityWarningsEnabled) {
                    $warnings[] = UploadQualityWarning::DimensionsBelowRecommended;
                } else {
                    return UploadValidationResult::failure(UploadValidationError::DimensionsTooSmall);
                }
            }
            // Oversized stays a hard rejection everywhere, hero included —
            // this is about the max-dimension ceiling (decode cost/abuse),
            // not aesthetics, so it never becomes a warning.
            if ($width > $limits['maxWidth'] || $height > $limits['maxHeight']) {
                return UploadValidationResult::failure(UploadValidationError::DimensionsTooLarge);
            }
            if ($qualityWarningsEnabled && $width < $height) {
                $warnings[] = UploadQualityWarning::PortraitAspectRatio;
            }
        }

        // SECURITY — content moderation. Evaluated unconditionally, after
        // warnings are collected but BEFORE they're ever acted on: a file
        // that both looks quality-questionable and fails moderation must
        // come back as a hard Rejected failure, never as "needs
        // confirmation", regardless of $qualityWarningsConfirmed.
        $moderation = $this->moderation->moderate($file->getPathname(), $mimeType);
        if (!$moderation->isAllowed) {
            return UploadValidationResult::failure(UploadValidationError::Rejected);
        }

        if ($warnings !== [] && !$qualityWarningsConfirmed) {
            return UploadValidationResult::needsConfirmation($warnings);
        }

        $safeFilename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_EXTENSIONS_BY_MIME[$mimeType];

        return UploadValidationResult::success($safeFilename, $mimeType, $warnings);
    }
}
