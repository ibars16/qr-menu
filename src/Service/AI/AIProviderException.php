<?php

namespace App\Service\AI;

/**
 * Thrown by any AIProviderInterface adapter on failure — network error,
 * non-2xx response, quota/rate limit, timeout, or a response shape that
 * can't be parsed. This is the signal AIModelRouter catches to move on to
 * the next configured provider.
 */
final class AIProviderException extends \RuntimeException
{
    public function __construct(
        public readonly string $providerId,
        public readonly AIFailureReason $reason,
        string $message,
        ?\Throwable $previous = null,
        // The provider's HTTP status when it answered with an error (e.g.
        // 503 "high demand"), so a caller can tell a temporary overload
        // from a real failure; null when there was no HTTP answer.
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
