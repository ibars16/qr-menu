<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatComplexityClassifierInterface;
use App\Service\AI\ChatMessage;

/**
 * What one Smart Waiter question sends to the AI: the restaurant's menu
 * context, the instruction built from it (SmartWaiterPromptBuilder), the
 * conversation's turns plus the new question, and the question's
 * complexity (which tier the router tries first).
 *
 * The one place this is assembled — the customer chat (SmartWaiterService)
 * and the manual model comparison (SmartWaiterModelComparison,
 * app:smart-waiter:ask --modelos) both go through it, so a comparison sees
 * exactly what a customer's question would send.
 */
final class SmartWaiterRequestBuilder
{
    public function __construct(
        private readonly MenuContextBuilder $menuContextBuilder,
        private readonly SmartWaiterPromptBuilder $promptBuilder,
        private readonly ChatComplexityClassifierInterface $complexityClassifier,
    ) {}

    /** @return array<string, mixed> this restaurant's menu, as MenuContextBuilder::build() shapes it */
    public function menuContext(Restaurant $restaurant, string $locale, string $currency): array
    {
        return $this->menuContextBuilder->build($restaurant, $locale, $currency);
    }

    /**
     * @param array<string, mixed> $menuContext from menuContext()
     * @param ChatMessage[]        $history     the conversation's recent turns, oldest first
     * @param list<string>         $earlierCustomerMessages
     * @param ?float               $timeout     null = the adapters' usual timeout (the customer chat)
     *
     * @return array{0: AIRequest, 1: string} the request, and the question's complexity ('simple'|'complex')
     */
    public function request(array $menuContext, string $locale, array $history, array $earlierCustomerMessages, ChatMessage $userMessage, ?float $timeout = null): array
    {
        $vocabulary = $this->menuContextBuilder->extractVocabulary($menuContext);
        $complexity = $this->complexityClassifier->classify($userMessage->content, $vocabulary);

        $request = new AIRequest(
            systemPrompt: $this->promptBuilder->build($menuContext, $locale, $earlierCustomerMessages),
            messages: [...$history, $userMessage],
            timeout: $timeout,
        );

        return [$request, $complexity];
    }
}
