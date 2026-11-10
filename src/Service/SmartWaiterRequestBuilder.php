<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatComplexityClassifierInterface;
use App\Service\AI\ChatMessage;

/**
 * What one Smart Waiter question sends to the AI: the restaurant's menu
 * context, sorted for this customer's needs (SmartWaiterDishFit), the
 * instruction built from it (SmartWaiterPromptBuilder), the conversation's
 * turns plus the new question, and the question's complexity (which tier
 * the router tries first).
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
        private readonly SmartWaiterDishFit $dishFit,
    ) {}

    /** @return array<string, mixed> this restaurant's menu, as MenuContextBuilder::build() shapes it */
    public function menuContext(Restaurant $restaurant, string $locale, string $currency): array
    {
        return $this->menuContextBuilder->build($restaurant, $locale, $currency);
    }

    /**
     * 'simple' | 'complex' — which tier the router tries first (after
     * smart_waiter_first). Known before any AI call, so even a question
     * whose needs detection fails is logged with it.
     *
     * @param array<string, mixed> $menuContext from menuContext()
     */
    public function complexity(array $menuContext, string $userMessage): string
    {
        return $this->complexityClassifier->classify($userMessage, $this->menuContextBuilder->extractVocabulary($menuContext));
    }

    /**
     * @param array<string, mixed> $menuContext from menuContext()
     * @param ChatMessage[]        $history     the conversation's recent turns, oldest first
     * @param list<string>         $earlierCustomerMessages
     * @param CustomerNeeds        $needs       what this customer must avoid (empty: the menu as it is)
     * @param ?string              $language    the customer's language as the needs detection gave it (null: not given)
     * @param ?float               $timeout     null = the adapters' usual timeout (the customer chat)
     *
     * @return array{0: AIRequest, 1: string, 2: bool} the request, the question's complexity
     *   ('simple'|'complex'), and whether any dish is "suitable" for $needs (SmartWaiterStaffNote)
     */
    public function request(array $menuContext, string $locale, array $history, array $earlierCustomerMessages, ChatMessage $userMessage, CustomerNeeds $needs, ?string $language = null, ?float $timeout = null): array
    {
        $sorted = $this->dishFit->apply($menuContext, $needs);
        $request = new AIRequest(
            systemPrompt: $this->promptBuilder->build($sorted, $locale, $earlierCustomerMessages, $needs, $language),
            messages: [...$history, $userMessage],
            timeout: $timeout,
        );

        return [$request, $this->complexity($menuContext, $userMessage->content), SmartWaiterDishFit::hasSuitable($sorted)];
    }
}
