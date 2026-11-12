<?php

namespace App\Service\DishPhoto;

enum DishPhotoQuotaResult
{
    case Reserved;
    /** Not paid yet, no card, DishPhotoQuota::TRIAL_LIMIT already used: "Elige un plan para crear más fotos con IA". */
    case TrialLimit;
    /** Not paid yet but a card is set, TRIAL_LIMIT used: "Tendrás más fotos cuando empiece tu plan". */
    case CardPending;
    /** Paid, this month's DishPhotoQuota::MONTHLY_LIMIT used: renews on the 1st. */
    case MonthlyLimit;
    /** The technical brake (ai_photo_* rate limiters). */
    case RateLimited;
}
