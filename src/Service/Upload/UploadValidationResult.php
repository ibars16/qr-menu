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
    ) {
    }

    /** @param UploadQualityWarning[] $warnings quality issues found — empty for a clean upload, non-empty only once the caller has confirmed them (see needsConfirmation()) */
    public static function success(string $safeFilename, string $mimeType, array $warnings = []): self
    {
        return new self(true, null, $warnings, $safeFilename, $mimeType);
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
    public static function needsConfirmation(array $warnings): self
    {
        return new self(false, null, $warnings, null, null);
    }
}
