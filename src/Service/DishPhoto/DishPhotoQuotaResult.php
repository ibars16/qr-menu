<?php

namespace App\Service\DishPhoto;

enum DishPhotoQuotaResult
{
    case Reserved;
    /** plan null and DishPhotoQuota::TRIAL_LIMIT already used: "Elige un plan para crear más fotos con IA". */
    case TrialLimit;
    /** The technical brake (ai_photo_* rate limiters). */
    case RateLimited;
}
