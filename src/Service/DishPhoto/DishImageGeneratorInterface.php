<?php

namespace App\Service\DishPhoto;

/**
 * One image from a text prompt, or a retouched copy of a photo.
 * Provider-neutral on purpose: Gemini today (GeminiDishImageGenerator),
 * OpenAI gpt-image possible later — chosen in config/services.yaml,
 * nothing else changes. Tests always use
 * App\Tests\Support\FakeDishImageGenerator, never a real provider.
 */
interface DishImageGeneratorInterface
{
    /** @throws DishImageGenerationException */
    public function generate(string $prompt): GeneratedImage;

    /**
     * "Mejorar con IA": $image (already reduced, see DishPhotoGenerator) edited
     * as $prompt says, coming out at $aspectRatio ('4:3': the menu's cards).
     *
     * @throws DishImageGenerationException
     */
    public function edit(string $prompt, GeneratedImage $image, string $mimeType, string $aspectRatio): GeneratedImage;
}
