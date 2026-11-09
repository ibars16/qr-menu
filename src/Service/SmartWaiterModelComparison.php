<?php

namespace App\Service;

use App\Controller\SmartWaiterController;
use App\Entity\Restaurant;
use App\Repository\RestaurantRepository;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIProviderInterface;
use App\Service\AI\ChatMessage;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Manual model comparison for app:smart-waiter:ask --modelos: asks ONE
 * given provider a Smart Waiter question, with no fallback, and with
 * exactly what the customer chat would send — the same
 * SmartWaiterRequestBuilder (menu, instruction, turns, earlier customer
 * messages) and the same history rules as SmartWaiterService (an answer
 * joins the history; a failed or cut-off one leaves the question
 * remembered as said but unanswered).
 *
 * Only reachable from the console command: nothing in the chat
 * (SmartWaiterController / SmartWaiterService) uses it, and the provider is
 * a PHP object handed in by the command — there is no request field that
 * could pick a model.
 *
 * What it never does, unlike the chat: write smart_waiter_exchange_log
 * (the owner's statistics stay clean), send the all-providers-failed alert
 * email, or keep anything once the command ends — each conversation lives
 * in an in-memory store (newConversationStore()), one per model.
 */
final class SmartWaiterModelComparison
{
    /** Seconds without data before a provider counts as timed out — longer than the chat's 20 s, to see a slow model's real answer. */
    public const DEFAULT_TIMEOUT = 60.0;

    /** @var array<string, array<string, mixed>> menu context per "restaurant id|locale" — built once per run, not per model and question */
    private array $menuContexts = [];

    public function __construct(
        private readonly SmartWaiterRequestBuilder $requestBuilder,
        private readonly AIProviderFactory $providerFactory,
        private readonly RestaurantRepository $restaurantRepository,
        private readonly MenuPreferencesResolver $menuPreferencesResolver,
    ) {}

    public function findRestaurant(string $slug): ?Restaurant
    {
        return $this->restaurantRepository->findOneBy(['slug' => $slug]);
    }

    /** The provider with this config/ai_providers.yaml id — manual_only ones included; null if unknown, disabled or without a key. */
    public function provider(string $id): ?AIProviderInterface
    {
        return $this->providerFactory->getProviderById($id);
    }

    /** An empty conversation store of its own, in memory only: one per model, so no model ever sees another's history. */
    public function newConversationStore(): SmartWaiterConversationStore
    {
        return new SmartWaiterConversationStore(new ArrayAdapter(), new NullLogger());
    }

    /** The chat's locale rule: the requested one if the menu supports it, else the restaurant's own language. */
    public function locale(Restaurant $restaurant, string $requested): string
    {
        return $this->menuPreferencesResolver->isLanguageSupported($requested) ? $requested : $restaurant->getDefaultLanguage();
    }

    /**
     * @return array{reply: string, error: string} error '' when it answered
     *         normally; a cut-off answer comes back as reply too (the chat
     *         would discard it — the error says so)
     */
    public function ask(AIProviderInterface $provider, SmartWaiterConversationStore $store, string $conversationId, Restaurant $restaurant, string $locale, string $question, float $timeout): array
    {
        if (mb_strlen($question) > SmartWaiterController::MAX_MESSAGE_LENGTH) {
            return ['reply' => '', 'error' => sprintf('pregunta de más de %d caracteres: el chat la rechaza', SmartWaiterController::MAX_MESSAGE_LENGTH)];
        }

        $restaurantId = $restaurant->getId();
        $userMessage = new ChatMessage('user', $question);
        [$request] = $this->requestBuilder->request(
            $this->menuContext($restaurant, $locale),
            $locale,
            $store->getHistory($conversationId, $restaurantId),
            $store->getEarlierCustomerMessages($conversationId, $restaurantId),
            $userMessage,
            $timeout,
        );

        try {
            $response = $provider->complete($request);
        } catch (AIProviderException $e) {
            $store->rememberUnansweredCustomerMessage($conversationId, $restaurantId, $userMessage);

            return ['reply' => '', 'error' => sprintf('%s: %s', $e->reason->value, $e->getMessage())];
        }

        if (!$response->endedNormally()) {
            $store->rememberUnansweredCustomerMessage($conversationId, $restaurantId, $userMessage);

            return ['reply' => $response->content, 'error' => sprintf('cortada (%s): el chat no la mostraría', $response->finishReason ?? 'sin motivo')];
        }

        $store->append($conversationId, $restaurantId, $userMessage, new ChatMessage('assistant', $response->content));

        return ['reply' => $response->content, 'error' => ''];
    }

    /** @return array<string, mixed> */
    private function menuContext(Restaurant $restaurant, string $locale): array
    {
        // The restaurant's own currency: what the chat quotes for a bare
        // language with no saved preference (MenuPreferencesResolver::guessCurrency()).
        return $this->menuContexts[$restaurant->getId() . '|' . $locale] ??= $this->requestBuilder->menuContext($restaurant, $locale, $restaurant->getCurrency());
    }
}
