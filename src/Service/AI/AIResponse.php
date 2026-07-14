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
    ) {}
}
