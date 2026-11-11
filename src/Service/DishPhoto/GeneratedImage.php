<?php

namespace App\Service\DishPhoto;

/** Raw bytes as the provider returned them (PNG or JPEG); DishPhotoStorage converts and validates them. */
final class GeneratedImage
{
    public function __construct(public readonly string $bytes)
    {
    }
}
