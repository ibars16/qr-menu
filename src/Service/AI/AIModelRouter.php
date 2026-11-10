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

    /**
     * $rejectCutOff: an answer that didn't end normally
     * (AIResponse::endedNormally() — cut off at max tokens, or stopped for
     * any other reason, whichever provider and however it says so) counts
     * as that provider failing (AIFailureReason::CUT_OFF, logged with its
     * finish reason) and the next one is tried. Off by default: the
     * translation callers deliberately receive the cut-off answer and
     * decide for themselves (see TranslationAiCaller). Smart Waiter turns
     * it on — a customer must never see a half answer.
     *
     * $tryFirst: provider ids tried before everyone else, in this order,
     * whatever the complexity — then the rest in the usual tier order, none
     * twice. An id that isn't available is skipped. Empty by default: only
     * Smart Waiter passes it (AIProviderFactory::getSmartWaiterFirst()), so
     * the translation callers' order never changes.
     *
     * $accept: when given, an answer it returns false for counts as that
     * provider failing (AIFailureReason::INVALID_RESPONSE) and the next one
     * is tried — e.g. SmartWaiterNeedsDetector's JSON that doesn't validate.
     *
     * @param list<string>                     $tryFirst
     * @param (\Closure(AIResponse): bool)|null $accept
     */
    public function route(AIRequest $request, string $complexity, bool $rejectCutOff = false, array $tryFirst = [], ?\Closure $accept = null): AIRouterResult
    {
        $providers = $this->providerFactory->getAvailableProviders();
        $primary = $complexity === 'complex' ? $providers['capable'] : $providers['fast'];
        $secondary = $complexity === 'complex' ? $providers['fast'] : $providers['capable'];

        $attempts = [];
        foreach (self::order([...$primary, ...$secondary], $tryFirst) as $provider) {
            try {
                $response = $provider->complete($request);
                if ($rejectCutOff && !$response->endedNormally()) {
                    $attempts[] = new AIProviderAttempt(
                        $response->providerId,
                        AIFailureReason::CUT_OFF,
                        sprintf('%s answer did not end normally (finish_reason=%s)', $response->providerId, $response->finishReason),
                    );
                    $this->logger->warning('AI answer did not end normally, trying next', [
                        'provider' => $response->providerId,
                        'finishReason' => $response->finishReason,
                    ]);
                    continue;
                }
                if ($accept !== null && !$accept($response)) {
                    $attempts[] = new AIProviderAttempt(
                        $response->providerId,
                        AIFailureReason::INVALID_RESPONSE,
                        sprintf('%s answer was not accepted by the caller', $response->providerId),
                    );
                    $this->logger->warning('AI answer not accepted, trying next', ['provider' => $response->providerId]);
                    continue;
                }

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

    /**
     * @param AIProviderInterface[] $providers in tier order
     * @param list<string>          $tryFirst
     *
     * @return AIProviderInterface[] the $tryFirst ones that are there, in that order, then the rest as they were
     */
    private static function order(array $providers, array $tryFirst): array
    {
        $first = [];
        foreach ($tryFirst as $id) {
            foreach ($providers as $i => $provider) {
                if ($provider->getId() === $id) {
                    $first[] = $provider;
                    unset($providers[$i]);
                    break;
                }
            }
        }

        return [...$first, ...array_values($providers)];
    }
}
