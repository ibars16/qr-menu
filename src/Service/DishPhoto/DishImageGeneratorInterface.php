<?php

namespace App\Service\DishPhoto;

/**
 * One image from a text prompt. Provider-neutral on purpose: Gemini today
 * (GeminiDishImageGenerator), OpenAI gpt-image possible later — chosen in
 * config/services.yaml, nothing else changes. Tests always use
 * App\Tests\Support\FakeDishImageGenerator, never a real provider.
 */
interface DishImageGeneratorInterface
{
    /** @throws DishImageGenerationException */
    public function generate(string $prompt): GeneratedImage;
}
