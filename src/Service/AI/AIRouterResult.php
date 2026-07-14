<?php

namespace App\Service\AI;

/**
 * The outcome of one routing decision: either a successful AIResponse from
 * whichever provider answered, or null if every configured provider — both
 * tiers — failed. `attempts` always lists every provider tried before that,
 * successful or not, so analytics can see how often fallback actually fires.
 */
final readonly class AIRouterResult
{
    /** @param AIProviderAttempt[] $attempts */
    public function __construct(
        public ?AIResponse $response,
        public array $attempts,
    ) {}

    public function isSuccess(): bool
    {
        return $this->response !== null;
    }
}
