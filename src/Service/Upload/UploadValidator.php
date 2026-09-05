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
        'menu_import_page' => [
            'maxSizeBytes'    => 15 * 1024 * 1024,
            'checkDimensions' => false,
        ],
    ];

    public function __construct(
        private readonly ContentModerationInterface $moderation,
    ) {
    }

    public function validate(UploadedFile $file, UploadProfile $profile): UploadValidationResult
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

        if ($limits['checkDimensions']) {
            $dimensions = @getimagesize($file->getPathname());
            if ($dimensions === false) {
                return UploadValidationResult::failure(UploadValidationError::UnsupportedType);
            }

            [$width, $height] = $dimensions;
            if ($width < $limits['minWidth'] || $height < $limits['minHeight']) {
                return UploadValidationResult::failure(UploadValidationError::DimensionsTooSmall);
            }
            if ($width > $limits['maxWidth'] || $height > $limits['maxHeight']) {
                return UploadValidationResult::failure(UploadValidationError::DimensionsTooLarge);
            }
        }

        $moderation = $this->moderation->moderate($file->getPathname(), $mimeType);
        if (!$moderation->isAllowed) {
            return UploadValidationResult::failure(UploadValidationError::Rejected);
        }

        $safeFilename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_EXTENSIONS_BY_MIME[$mimeType];

        return UploadValidationResult::success($safeFilename, $mimeType);
    }
}
