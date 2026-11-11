<?php

namespace App\Service\DishPhoto;

/**
 * Whether AI dish photos can be offered at all: only with their own key,
 * GEMINI_IMAGE_API_KEY (the text key, GEMINI_API_KEY, is never a fallback —
 * translations and Smart Waiter keep it to themselves). Empty → no
 * "Generar con IA" button, no batch offer after an import, no Carta notice.
 */
final class DishPhotoAvailability
{
    public function __construct(private readonly string $imageApiKey)
    {
    }

    public function isAvailable(): bool
    {
        return trim($this->imageApiKey) !== '';
    }
}
