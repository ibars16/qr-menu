<?php

namespace App\Service\AI;

/**
 * What a provider adapter hands back on success. Token counts are whatever
 * the provider actually reported — never estimated — so a provider that
 * doesn't report usage simply leaves them null rather than guessing.
 */
final readonly class AIResponse
{
    public function __construct(
        public string $content,
        public string $providerId,
        public string $model,
        public int $latencyMs,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        // Why the model stopped: 'stop', 'length' (cut off at maxTokens —
        // reasoning models count their thinking too), or the provider's
        // own value lowercased; null when not reported. Informative only:
        // each caller decides whether a cut-off answer is usable.
        public ?string $finishReason = null,
    ) {}

    /**
     * True only when the model ended its answer itself: 'stop' (every
     * OpenAI-compatible provider — Groq, OpenAI, Mistral, DeepSeek,
     * OpenRouter — and Gemini's STOP), or no reason reported at all.
     * Anything else means the text may be incomplete: 'length' (cut off at
     * max tokens; Gemini's MAX_TOKENS), Mistral's 'model_length', DeepSeek's
     * 'insufficient_system_resource', OpenRouter's 'error', a
     * 'content_filter', Gemini's SAFETY / RECITATION / OTHER…
     */
    public function endedNormally(): bool
    {
        return $this->finishReason === null || $this->finishReason === 'stop';
    }
}
