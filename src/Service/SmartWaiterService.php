<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Entity\SmartWaiterExchangeLog;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatComplexityClassifierInterface;
use App\Service\AI\ChatMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrates one chat exchange end to end: load short-term memory, build
 * this restaurant's own menu context, classify complexity, route to a
 * provider, remember the exchange (cache) and log it (DB, aggregated only).
 *
 * The restaurant passed in must already be resolved server-side by the
 * caller (SmartWaiterController, from the URL slug) — this class never
 * looks a restaurant up itself, which is what makes "the model only ever
 * receives one restaurant's data" true by construction rather than by
 * prompt instruction.
 */
final class SmartWaiterService
{
    public function __construct(
        private readonly MenuContextBuilder $menuContextBuilder,
        private readonly SmartWaiterPromptBuilder $promptBuilder,
        private readonly ChatComplexityClassifierInterface $complexityClassifier,
        private readonly AIModelRouter $router,
        private readonly AIProviderFactory $providerFactory,
        private readonly SmartWaiterConversationStore $conversationStore,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return array{conversationId: string, reply: ?string, error: bool} */
    public function reply(Restaurant $restaurant, string $locale, ?string $conversationId, string $userMessage): array
    {
        $conversationId ??= $this->conversationStore->newConversationId();
        $restaurantId = $restaurant->getId();

        $history = $this->conversationStore->getHistory($conversationId, $restaurantId);

        $menuContext = $this->menuContextBuilder->build($restaurant, $locale);
        $vocabulary = $this->menuContextBuilder->extractVocabulary($menuContext);
        $complexity = $this->complexityClassifier->classify($userMessage, $vocabulary);

        $userChatMessage = new ChatMessage('user', $userMessage);
        $aiRequest = new AIRequest(
            systemPrompt: $this->promptBuilder->build($menuContext, $locale),
            messages: [...$history, $userChatMessage],
        );

        $log = new SmartWaiterExchangeLog($restaurant, $conversationId, $locale, $complexity);
        $result = $this->router->route($aiRequest, $complexity);

        if (!$result->isSuccess()) {
            $attempts = $result->attempts;
            $lastAttempt = end($attempts) ?: null;
            $log->recordFailure(count($result->attempts), $lastAttempt?->reason);
            $this->em->persist($log);
            $this->em->flush();

            return ['conversationId' => $conversationId, 'reply' => null, 'error' => true];
        }

        $response = $result->response;
        $pricing = $this->providerFactory->getPricing($response->providerId);
        $cost = $this->estimateCost($pricing, $response->promptTokens, $response->completionTokens);

        $log->recordSuccess(
            providerId: $response->providerId,
            model: $response->model,
            attemptsCount: count($result->attempts) + 1,
            latencyMs: $response->latencyMs,
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
            estimatedCostUsd: $cost,
        );
        $this->em->persist($log);
        $this->em->flush();

        $this->conversationStore->append(
            $conversationId,
            $restaurantId,
            $userChatMessage,
            new ChatMessage('assistant', $response->content),
        );

        return ['conversationId' => $conversationId, 'reply' => $response->content, 'error' => false];
    }

    /** @param array{input: ?float, output: ?float}|null $pricing */
    private function estimateCost(?array $pricing, ?int $promptTokens, ?int $completionTokens): ?float
    {
        if ($pricing === null || $pricing['input'] === null || $pricing['output'] === null) {
            return null;
        }
        if ($promptTokens === null || $completionTokens === null) {
            return null;
        }

        return ($promptTokens / 1_000_000 * $pricing['input']) + ($completionTokens / 1_000_000 * $pricing['output']);
    }
}
