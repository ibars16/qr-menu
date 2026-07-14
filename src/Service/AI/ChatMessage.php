<?php

namespace App\Service\AI;

/**
 * One turn of a conversation, provider-agnostic. Each adapter maps `role`
 * onto whatever shape its own API expects (e.g. Gemini's "model" vs the
 * OpenAI-compatible "assistant").
 */
final readonly class ChatMessage
{
    public function __construct(
        public string $role, // 'user' | 'assistant'
        public string $content,
    ) {}
}
