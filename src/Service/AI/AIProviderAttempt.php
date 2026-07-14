<?php

namespace App\Service\AI;

/** A single failed attempt within one routing decision — kept for logging, never persisted per-attempt. */
final readonly class AIProviderAttempt
{
    public function __construct(
        public string $providerId,
        public AIFailureReason $reason,
        public string $message,
    ) {}
}
