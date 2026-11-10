<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Entity\SmartWaiterExchangeLog;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderAttempt;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\ChatMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrates one chat exchange end to end: load short-term memory, find
 * what the customer must avoid (SmartWaiterNeedsDetector, a small AI call),
 * build the request (SmartWaiterRequestBuilder: this restaurant's own menu
 * context sorted for those needs, the instruction, complexity), route to a
 * provider, add the fixed staff sentence when the customer has an allergy
 * (SmartWaiterStaffNote), remember the exchange (cache) and log it (DB,
 * aggregated only).
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
        private readonly SmartWaiterRequestBuilder $requestBuilder,
        private readonly AIModelRouter $router,
        private readonly AIProviderFactory $providerFactory,
        private readonly SmartWaiterConversationStore $conversationStore,
        private readonly SmartWaiterFailureAlertMailer $failureAlertMailer,
        private readonly EntityManagerInterface $em,
        private readonly SmartWaiterReplyCleaner $replyCleaner,
        private readonly SmartWaiterNeedsDetector $needsDetector,
        private readonly SmartWaiterStaffNote $staffNote,
    ) {}

    /** @return array{conversationId: string, reply: ?string, error: bool} */
    public function reply(Restaurant $restaurant, string $locale, string $currency, ?string $conversationId, string $userMessage): array
    {
        $conversationId ??= $this->conversationStore->newConversationId();
        $restaurantId = $restaurant->getId();

        $history = $this->conversationStore->getHistory($conversationId, $restaurantId);
        $earlierCustomerMessages = $this->conversationStore->getEarlierCustomerMessages($conversationId, $restaurantId);

        $userChatMessage = new ChatMessage('user', $userMessage);
        $menuContext = $this->requestBuilder->menuContext($restaurant, $locale, $currency);
        $log = new SmartWaiterExchangeLog($restaurant, $conversationId, $locale, $this->requestBuilder->complexity($menuContext, $userMessage));

        // Step 1: what this customer must avoid — a small AI call on what
        // they just wrote (plus any message whose detection failed before),
        // merged into what the conversation already knows.
        [$detected, $detectionAttempts, $language] = $this->needsDetector->detect(
            [...$this->conversationStore->getPendingDetection($conversationId, $restaurantId), $userMessage],
            $this->conversationStore->getLastReply($conversationId, $restaurantId),
        );
        if ($detected === null) {
            // The safe side: never answer with dishes without having checked
            // what the customer said. They see the usual fallback; the message
            // is remembered (said, unanswered) and detected again next time.
            return $this->fail($restaurant, $log, $detectionAttempts, $conversationId, $userChatMessage, pendingDetection: true);
        }
        $needs = $this->conversationStore->addNeeds($conversationId, $restaurantId, $detected);

        // Steps 2–3: the dishes sorted for those needs (SmartWaiterDishFit), then the answer.
        [$aiRequest, $complexity, $anySuitable] = $this->requestBuilder->request(
            $menuContext,
            $locale,
            $history,
            $earlierCustomerMessages,
            $userChatMessage,
            $needs,
            $language,
        );
        // rejectCutOff: a reply that didn't end normally is never shown nor
        // kept in the history — the next provider answers instead.
        // tryFirst: Smart Waiter's own order (config/ai_providers.yaml's
        // smart_waiter_first), simple and complex alike.
        $result = $this->router->route($aiRequest, $complexity, rejectCutOff: true, tryFirst: $this->providerFactory->getSmartWaiterFirst());

        if (!$result->isSuccess()) {
            return $this->fail($restaurant, $log, $result->attempts, $conversationId, $userChatMessage, pendingDetection: false);
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

        // The history keeps the cleaned reply — no formatting the model
        // could copy from its own earlier replies — WITHOUT the staff
        // sentence our code adds (SmartWaiterStaffNote): the AI never sees
        // it as its own, so never writes it itself.
        $language = $this->staffNote->language($language, $locale);
        $reply = $this->replyCleaner->clean($response->content, $language);
        $this->conversationStore->append(
            $conversationId,
            $restaurantId,
            $userChatMessage,
            new ChatMessage('assistant', $reply),
        );

        return [
            'conversationId' => $conversationId,
            'reply' => $this->staffNote->append($reply, $this->staffNote->note($needs, $anySuitable, $language)),
            'error' => false,
        ];
    }

    /**
     * Every provider failed — at the needs detection or at the answer.
     *
     * @param list<AIProviderAttempt> $attempts
     *
     * @return array{conversationId: string, reply: null, error: true}
     */
    private function fail(Restaurant $restaurant, SmartWaiterExchangeLog $log, array $attempts, string $conversationId, ChatMessage $userChatMessage, bool $pendingDetection): array
    {
        $lastAttempt = end($attempts) ?: null;
        $log->recordFailure(count($attempts), $lastAttempt?->reason);
        $this->em->persist($log);
        $this->em->flush();

        // Unanswered, but still said: e.g. "I'm allergic to bread" must
        // reach the next question even though this one got no reply.
        $this->conversationStore->rememberUnansweredCustomerMessage($conversationId, $restaurant->getId(), $userChatMessage);
        if ($pendingDetection) {
            $this->conversationStore->addPendingDetection($conversationId, $restaurant->getId(), $userChatMessage->content);
        }

        // Every configured provider just failed — the customer is
        // about to see the generic fallback message (see
        // _smart_waiter.html.twig's errorFallback). Alert both the
        // platform owner and this restaurant's own staff so a human
        // can step in and, ideally, so someone actually fixes it.
        $this->failureAlertMailer->alert($restaurant, $attempts);

        return ['conversationId' => $conversationId, 'reply' => null, 'error' => true];
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
