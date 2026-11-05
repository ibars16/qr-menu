<?php

namespace App\Service\AI;

/**
 * A fully-assembled request to send to whichever provider the router picks.
 * Provider-agnostic on purpose — nothing in here is shaped for one API.
 */
final readonly class AIRequest
{
    /** @param ChatMessage[] $messages Conversation history plus the new user message, in order. */
    public function __construct(
        public string $systemPrompt,
        public array $messages,
        public int $maxTokens = 600,
        public float $temperature = 0.4,
        // Total seconds the whole request may take (HttpClient max_duration);
        // null = only the adapter's usual inactivity timeout.
        public ?float $maxDuration = null,
    ) {}
}
