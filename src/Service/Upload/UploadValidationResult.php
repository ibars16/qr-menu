<?php

namespace App\Service\Upload;

final class UploadValidationResult
{
    private function __construct(
        public readonly bool $isValid,
        public readonly ?UploadValidationError $error,
        public readonly ?string $safeFilename,
        public readonly ?string $mimeType,
    ) {
    }

    public static function success(string $safeFilename, string $mimeType): self
    {
        return new self(true, null, $safeFilename, $mimeType);
    }

    public static function failure(UploadValidationError $error): self
    {
        return new self(false, $error, null, null);
    }
}
