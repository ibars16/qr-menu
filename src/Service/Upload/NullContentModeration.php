<?php

namespace App\Service\Upload;

/**
 * Placeholder implementation: always allows. See ContentModerationInterface
 * for why — this is wiring, not a real moderation safeguard.
 */
final class NullContentModeration implements ContentModerationInterface
{
    public function moderate(string $filePath, string $mimeType): ContentModerationResult
    {
        return ContentModerationResult::allowed();
    }
}
