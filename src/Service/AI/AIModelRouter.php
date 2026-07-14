<?php

namespace App\Service\AI;

use Psr\Log\LoggerInterface;

/**
 * Tries providers in tier order for the given complexity, catching
 * AIProviderException and moving to the next one — a failed, unavailable,
 * quota-exhausted, or rate-limited provider never surfaces to the customer
 * as anything other than a slightly later answer from someone else. Always
 * falls through to the *other* tier too, rather than giving up when a whole
 * tier is down: a fast-tier answer to a complex question beats no answer.
 *
 * Only returns null (via AIRouterResult::isSuccess() === false) when every
 * configured, available provider across both tiers has failed — the caller
 * is responsible for the one honest thing to say at that point, never for
 * inventing an answer.
 */
final class AIModelRouter
{
    public function __construct(
        private readonly AIProviderFactory $providerFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function route(AIRequest $request, string $complexity): AIRouterResult
    {
        $providers = $this->providerFactory->getAvailableProviders();
        $primary = $complexity === 'complex' ? $providers['capable'] : $providers['fast'];
        $secondary = $complexity === 'complex' ? $providers['fast'] : $providers['capable'];

        $attempts = [];
        foreach ([...$primary, ...$secondary] as $provider) {
            try {
                $response = $provider->complete($request);
                return new AIRouterResult($response, $attempts);
            } catch (AIProviderException $e) {
                $attempts[] = new AIProviderAttempt($e->providerId, $e->reason, $e->getMessage());
                $this->logger->warning('AI provider failed, trying next', [
                    'provider' => $e->providerId,
                    'reason' => $e->reason->value,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (empty($attempts) && empty($primary) && empty($secondary)) {
            $this->logger->error('No AI providers are configured at all — every api_key_env is empty or a placeholder.');
        }

        return new AIRouterResult(null, $attempts);
    }
}
