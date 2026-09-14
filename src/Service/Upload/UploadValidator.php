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
    private const ALLOWED_IMAGE_EXTENSIONS_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * mp4 only for now — webm uses a completely different container format
     * (Matroska/EBML, not ISO-BMFF), so accepting it would mean a second,
     * unrelated duration parser alongside readMp4DurationSeconds() below.
     * Duration is the one hard guarantee here ("corto" must actually be
     * enforced), so it doesn't get to be the thing skipped for webm's
     * sake — revisit only if webm support is asked for on its own.
     */
    private const ALLOWED_VIDEO_EXTENSIONS_BY_MIME = [
        'video/mp4' => 'mp4',
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
        /**
         * The dish "clip" — a short, silent, looping animation, not a video
         * with sound/controls (see MenuAdminController::uploadProductClip()).
         * No dimension check (checkDimensions: false, same as
         * menu_import_page — resolution isn't the concern here); duration
         * is, since "short" is the entire product promise. maxDurationSeconds
         * is enforced server-side by reading the file's own moov/mvhd box
         * (readMp4DurationSeconds() below) — never trust a client-reported
         * duration for this, only the client-side hint that saves the owner
         * a wasted upload.
         */
        'dish_clip' => [
            'maxSizeBytes'       => 8 * 1024 * 1024,
            'checkDimensions'    => false,
            'maxDurationSeconds' => 10,
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
            // UPLOAD_ERR_INI_SIZE/FORM_SIZE mean PHP truncated the file for
            // exceeding upload_max_filesize/post_max_size before it ever
            // reached here — that's a size problem, not "no valid file was
            // picked", so it gets the same honest TooLarge message as our
            // own maxSizeBytes check below rather than the generic one.
            if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                return UploadValidationResult::failure(UploadValidationError::TooLarge);
            }

            return UploadValidationResult::failure(UploadValidationError::InvalidFile);
        }

        $limits = self::LIMITS[$profile->value];
        $isVideo = $profile === UploadProfile::DishClip;
        $allowedExtensions = $isVideo ? self::ALLOWED_VIDEO_EXTENSIONS_BY_MIME : self::ALLOWED_IMAGE_EXTENSIONS_BY_MIME;

        // Real content-sniffed MIME type (finfo over the file's actual
        // bytes), never the client-supplied filename or Content-Type header.
        $mimeType = $file->getMimeType();
        if (!isset($allowedExtensions[$mimeType])) {
            return UploadValidationResult::failure(UploadValidationError::UnsupportedType);
        }

        if ($file->getSize() > $limits['maxSizeBytes']) {
            return UploadValidationResult::failure(UploadValidationError::TooLarge);
        }

        $warnings = [];

        if ($isVideo) {
            // Fail closed: a clip we can't confidently read a duration out
            // of (malformed/truncated/exotic container) is treated the same
            // as an unsupported type, never let through unchecked — the
            // duration cap is the one guarantee this profile exists for.
            $durationSeconds = $this->readMp4DurationSeconds($file->getPathname());
            if ($durationSeconds === null) {
                return UploadValidationResult::failure(UploadValidationError::UnsupportedType);
            }
            if ($durationSeconds > $limits['maxDurationSeconds']) {
                return UploadValidationResult::failure(UploadValidationError::DurationTooLong);
            }
        }

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

        $safeFilename = bin2hex(random_bytes(16)) . '.' . $allowedExtensions[$mimeType];

        return UploadValidationResult::success($safeFilename, $mimeType, $warnings);
    }

    /**
     * Reads the movie duration straight out of an MP4/ISO-BMFF file's
     * moov/mvhd box — no ffprobe, no dependency, just the handful of bytes
     * the container format guarantees are there for any player to read.
     * Returns null (never a guess) for anything that doesn't look like a
     * well-formed MP4, so validate() fails closed rather than let an
     * unreadable duration slip through unchecked.
     */
    private function readMp4DurationSeconds(string $path): ?float
    {
        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize < 8) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $moov = $this->findBox($handle, 0, $fileSize, 'moov');
            if ($moov === null) {
                return null;
            }
            [$moovOffset, $moovLength] = $moov;

            $mvhd = $this->findBox($handle, $moovOffset, $moovLength, 'mvhd');
            if ($mvhd === null) {
                return null;
            }
            [$mvhdOffset, $mvhdLength] = $mvhd;

            fseek($handle, $mvhdOffset);
            $versionByte = fread($handle, 1);
            if ($versionByte === false || $versionByte === '') {
                return null;
            }
            $version = ord($versionByte);

            // mvhd body: version(1) + flags(3), then either the 32-bit
            // (version 0) or 64-bit (version 1) creation/modification times,
            // then timescale (always 32-bit) + duration (32 or 64-bit).
            if (1 === $version) {
                if ($mvhdLength < 32) {
                    return null;
                }
                fseek($handle, $mvhdOffset + 20);
                $data = fread($handle, 12);
                if ($data === false || \strlen($data) < 12) {
                    return null;
                }
                $timescale = unpack('N', substr($data, 0, 4))[1];
                $duration = ($this->readUint32($data, 4) << 32) | $this->readUint32($data, 8);
            } else {
                if ($mvhdLength < 20) {
                    return null;
                }
                fseek($handle, $mvhdOffset + 12);
                $data = fread($handle, 8);
                if ($data === false || \strlen($data) < 8) {
                    return null;
                }
                $timescale = $this->readUint32($data, 0);
                $duration = $this->readUint32($data, 4);
            }

            if ($timescale <= 0) {
                return null;
            }

            return $duration / $timescale;
        } finally {
            fclose($handle);
        }
    }

    private function readUint32(string $data, int $offset): int
    {
        return unpack('N', substr($data, $offset, 4))[1];
    }

    /**
     * Finds the first box of $type within [$start, $start + $length) of an
     * ISO-BMFF file and returns [dataOffset, dataSize] — the range just past
     * that box's own header. Returns null if not found, or the moment any
     * box header looks malformed (a size that would read past the searched
     * range) — never guesses past bytes it can't account for.
     *
     * @return array{0:int,1:int}|null
     */
    private function findBox($handle, int $start, int $length, string $type): ?array
    {
        $end = $start + $length;
        $pos = $start;

        while ($pos + 8 <= $end) {
            fseek($handle, $pos);
            $header = fread($handle, 8);
            if ($header === false || \strlen($header) < 8) {
                return null;
            }

            $size = $this->readUint32($header, 0);
            $boxType = substr($header, 4, 4);
            $headerSize = 8;

            if (1 === $size) {
                $ext = fread($handle, 8);
                if ($ext === false || \strlen($ext) < 8) {
                    return null;
                }
                $size = ($this->readUint32($ext, 0) << 32) | $this->readUint32($ext, 4);
                $headerSize = 16;
            } elseif (0 === $size) {
                // "extends to end of file" in the spec — here, to the end
                // of whatever range we were asked to search.
                $size = $end - $pos;
            }

            if ($size < $headerSize || $pos + $size > $end) {
                return null;
            }

            if ($boxType === $type) {
                return [$pos + $headerSize, $size - $headerSize];
            }

            $pos += $size;
        }

        return null;
    }
}
