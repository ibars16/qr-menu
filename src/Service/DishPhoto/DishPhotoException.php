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
    /** Not paid yet, card set, trial photos used up: more once the plan starts. */
    public const REASON_CARD_PENDING = 'card_pending';
    /** Paid, this month's photos used up: renews on the 1st (its message takes %month%). */
    public const REASON_MONTHLY_LIMIT = 'monthly_limit';
    /** "Mejorar con IA" on a dish whose photo isn't the owner's own (AI-made, retouched, a clip's poster, none). */
    public const REASON_NO_OWNER_PHOTO = 'no_owner_photo';
    /** "Mejorar con IA": the provider gave no retouched photo (Refused, with its own message). */
    public const REASON_ENHANCE_REFUSED = 'enhance_refused';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct('AI dish photo: ' . $reason, 0, $previous);
    }

    public static function fromFailure(DishImageGenerationException $e): self
    {
        return new self($e->failure->value, $e);
    }

    /** One of the caps (DishPhotoQuota) — not a provider problem; the trial ones end a trial run. */
    public function isTrialOver(): bool
    {
        return in_array($this->reason, [self::REASON_TRIAL_LIMIT, self::REASON_CARD_PENDING], true);
    }

    /** Gemini itself is unavailable or not set up: the batch pauses ("Reintentar") instead of skipping the dish. */
    public function isProviderDown(): bool
    {
        return in_array($this->reason, [DishImageFailure::Unavailable->value, DishImageFailure::NotConfigured->value], true);
    }
}
