<?php

namespace App\Service\AI;

/**
 * What a vision adapter hands back on success — the raw decoded JSON the
 * model produced (see MenuVisionPromptBuilder for the schema it's asked to
 * follow), plus the same provider/usage metadata AIResponse carries. This is
 * deliberately not AIResponse itself: AIResponse::$content is a plain
 * string built for chat replies, and re-serializing structured extraction
 * data through it just to decode it again on the other side is pointless
 * indirection for a feature that doesn't share anything else with the chat
 * flow (no conversation history, no system-prompt-plus-messages shape).
 */
final readonly class MenuVisionResult
{
    public function __construct(
        public array $data,
        public string $providerId,
        public string $model,
        public int $latencyMs,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
    ) {}
}
