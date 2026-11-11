<?php

namespace App\Service\DishPhoto;

/**
 * No photo for the owner. $reason is one of the REASON_* values — also the
 * suffix of its message key, ai_photo.error.<reason> (admin_menu domain).
 */
final class DishPhotoException extends \RuntimeException
{
    public const REASON_TRIAL_LIMIT = 'trial_limit';
    public const REASON_RATE_LIMITED = 'rate_limited';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct('AI dish photo: ' . $reason, 0, $previous);
    }

    public static function fromFailure(DishImageGenerationException $e): self
    {
        return new self($e->failure->value, $e);
    }

    /** Gemini itself is unavailable or not set up: the batch pauses ("Reintentar") instead of skipping the dish. */
    public function isProviderDown(): bool
    {
        return in_array($this->reason, [DishImageFailure::Unavailable->value, DishImageFailure::NotConfigured->value], true);
    }
}
