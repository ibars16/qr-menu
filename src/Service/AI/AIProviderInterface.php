<?php

namespace App\Service\AI;

/**
 * A single AI chat backend. New providers are added through
 * config/ai_providers.yaml — via AIProviderFactory — without ever touching
 * this interface or the router: the OpenAI-compatible shape covers OpenAI,
 * Groq, OpenRouter, Mistral, and DeepSeek as one adapter class, and Gemini
 * gets its own because its request/response shape genuinely differs.
 */
interface AIProviderInterface
{
    /** The `id` from config/ai_providers.yaml — never the model name, never the display name. */
    public function getId(): string;

    public function getModel(): string;

    /** @throws AIProviderException on any failure — never returns a partial or fabricated response. */
    public function complete(AIRequest $request): AIResponse;
}
