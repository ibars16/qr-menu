<?php

namespace App\Service\DishPhoto;

/** Why a provider gave no photo — decides the owner's message and whether the batch skips or pauses. */
enum DishImageFailure: string
{
    /** Refused by the provider's safety filters, or answered without an image: retrying the same dish won't help. */
    case Refused = 'refused';
    /** Took longer than the timeout. */
    case Timeout = 'timeout';
    /** Rate-limited (429), provider error (5xx) or network down: worth retrying later, not a problem with the dish. */
    case Unavailable = 'unavailable';
    /** The provider isn't configured (no API key). */
    case NotConfigured = 'not_configured';
}
