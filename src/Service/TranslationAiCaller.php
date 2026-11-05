<?php

namespace App\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;
use Psr\Log\LoggerInterface;

/**
 * The AI calls of the dish and label translators (RouterProductTranslator,
 * RouterLabelTranslator), in one place.
 *
 * - complete(): the free groq-llama provider first, then AIModelRouter's
 *   'complex' chain (capable tier, then fast — Gemini) — what a Groq out of
 *   tokens falls back to. Returns the text and which provider wrote it.
 * - completeElsewhere(): the same request to any available provider BUT the
 *   given one, capable tier first — the review pass when it must be done by
 *   a different AI than the one that translated (the menu's main-language
 *   change, see IndependentReviewTranslator). A failure, or an answer cut
 *   off at maxTokens, moves on to the next one; none left = null. It all
 *   waits at most ELSEWHERE_MAX_SECONDS: slower = null (the translation
 *   stays and the conversion goes on).
 */
final class TranslationAiCaller
{
    /** The whole review by another AI (every provider tried) waits at most this long; then the translation stays. */
    public const ELSEWHERE_MAX_SECONDS = 10.0;
    /** Not worth starting another provider with less time than this left. */
    private const ELSEWHERE_MIN_SECONDS = 1.0;

    public function __construct(
        private readonly AIModelRouter     $router,
        private readonly AIProviderFactory $providerFactory,
        private readonly LoggerInterface   $logger,
    ) {}

    /** @return array{content: string, provider: string}|null */
    public function complete(string $systemPrompt, string $userContent, int $maxTokens): ?array
    {
        $request = $this->request($systemPrompt, $userContent, $maxTokens);

        $freeProvider = $this->providerFactory->getProviderById('groq-llama');
        if ($freeProvider !== null) {
            try {
                $response = $freeProvider->complete($request);

                return ['content' => $response->content, 'provider' => $response->providerId];
            } catch (AIProviderException $e) {
                $this->logger->warning('groq-llama call failed, falling back to AIModelRouter', [
                    'reason'  => $e->reason->value,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $result = $this->router->route($request, 'complex');

        return $result->isSuccess() ? ['content' => $result->response->content, 'provider' => $result->response->providerId] : null;
    }

    public function completeElsewhere(string $systemPrompt, string $userContent, int $maxTokens, string $notProvider): ?string
    {
        $providers = $this->providerFactory->getAvailableProviders();
        $deadline  = microtime(true) + self::ELSEWHERE_MAX_SECONDS;

        foreach ([...$providers['capable'], ...$providers['fast']] as $provider) {
            if ($provider->getId() === $notProvider) {
                continue;
            }
            $left = $deadline - microtime(true);
            if ($left < self::ELSEWHERE_MIN_SECONDS) {
                $this->logger->warning('Independent translation review: out of time; the translation is kept as it is.', [
                    'translatedBy' => $notProvider,
                    'maxSeconds'   => self::ELSEWHERE_MAX_SECONDS,
                ]);

                return null;
            }
            try {
                $response = $provider->complete($this->request($systemPrompt, $userContent, $maxTokens, round($left, 1)));
            } catch (AIProviderException $e) {
                $this->logger->warning('Independent translation review: provider failed, trying the next one.', [
                    'provider' => $provider->getId(),
                    'reason'   => $e->reason->value,
                ]);
                continue;
            } catch (\Throwable $e) {
                // max_duration reached surfaces as a transport error, not an AIProviderException.
                $this->logger->warning('Independent translation review: provider failed, trying the next one.', [
                    'provider' => $provider->getId(),
                    'reason'   => microtime(true) >= $deadline - 0.05 ? 'too slow (over ' . self::ELSEWHERE_MAX_SECONDS . ' s)' : 'transport error',
                ]);
                continue;
            }
            if ($response->finishReason === 'length') {
                $this->logger->warning('Independent translation review: answer cut off, trying the next one.', [
                    'provider' => $provider->getId(),
                    'reason'   => 'cut off (finish_reason=length)',
                ]);
                continue;
            }

            return $response->content;
        }

        $this->logger->info('Independent translation review: no other provider answered; the translation is kept as it is.', [
            'translatedBy' => $notProvider,
        ]);

        return null;
    }

    private function request(string $systemPrompt, string $userContent, int $maxTokens, ?float $maxDuration = null): AIRequest
    {
        return new AIRequest(
            systemPrompt: $systemPrompt,
            messages: [new ChatMessage('user', $userContent)],
            maxTokens: $maxTokens,
            temperature: 0.1,
            maxDuration: $maxDuration,
        );
    }
}
