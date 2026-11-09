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
        // Seconds without any data before giving up (HttpClient timeout);
        // null = the adapter's usual 20 s. The customer chat never sets it —
        // only a manual tool (app:smart-waiter:ask --modelos) waits longer.
        public ?float $timeout = null,
    ) {}
}
