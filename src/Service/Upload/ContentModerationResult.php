<?php

namespace App\Service\Upload;

final class ContentModerationResult
{
    private function __construct(public readonly bool $isAllowed)
    {
    }

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function rejected(): self
    {
        return new self(false);
    }
}
