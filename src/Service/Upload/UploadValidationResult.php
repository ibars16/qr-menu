<?php

namespace App\Service\Upload;

final class UploadValidationResult
{
    private function __construct(
        public readonly bool $isValid,
        public readonly ?UploadValidationError $error,
        /** @var UploadQualityWarning[] */
        public readonly array $warnings,
        public readonly ?string $safeFilename,
        public readonly ?string $mimeType,
        /** Clip profile only — read from the file itself; null for images. */
        public readonly ?float $durationSeconds = null,
        /** Read from the file itself, for profiles with a dimension check; null otherwise. */
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
    }

    /** @param UploadQualityWarning[] $warnings quality issues found — empty for a clean upload; informative ones (see UploadQualityWarning::requiresConfirmation()) or ones the caller has already confirmed (see needsConfirmation()) */
    public static function success(string $safeFilename, string $mimeType, array $warnings = [], ?float $durationSeconds = null, ?int $width = null, ?int $height = null): self
    {
        return new self(true, null, $warnings, $safeFilename, $mimeType, $durationSeconds, $width, $height);
    }

    /** A hard, security-relevant rejection. Never overridable by a quality-warning confirmation flag. */
    public static function failure(UploadValidationError $error): self
    {
        return new self(false, $error, [], null, null);
    }

    /**
     * Quality issues found that the uploader hasn't confirmed yet — NOT a
     * security failure (isValid is false only because nothing should be
     * persisted until the caller re-validates with the issues acknowledged).
     * $error stays null here specifically so callers can tell this apart
     * from a real UploadValidationError: `$result->error !== null` means
     * "reject, full stop"; `!$result->isValid && $result->error === null`
     * means "show these warnings and offer to continue".
     *
     * @param UploadQualityWarning[] $warnings
     */
    public static function needsConfirmation(array $warnings, ?int $width = null, ?int $height = null): self
    {
        return new self(false, null, $warnings, null, null, null, $width, $height);
    }
}
