<?php

namespace App\Service;

use App\Service\AI\ChatMessage;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Short-term memory for one chat session — cache only, sliding TTL, capped
 * length. There is deliberately no database table backing this: closing the
 * tab (sessionStorage on the client) or letting the TTL lapse is the only
 * way a conversation ever ends, and either way nothing durable is left
 * behind. See SmartWaiterExchangeLog for what *is* kept permanently
 * (aggregated, no message content).
 *
 * Two parts:
 * - the recent turns (getHistory()): the last MAX_HISTORY_MESSAGES
 *   messages, customer and waiter, sent to the AI as turns;
 * - the earlier customer messages (getEarlierCustomerMessages()): every
 *   customer message that is NOT among those turns — pushed out of the
 *   window, or never answered because every provider failed
 *   (rememberUnansweredCustomerMessage()) — so an allergy said many
 *   questions ago still reaches the AI (SmartWaiterPromptBuilder's EARLIER
 *   CUSTOMER MESSAGES block). Only the customer's words; old waiter replies
 *   are dropped. Capped at MAX_EARLIER_CUSTOMER_MESSAGES and
 *   MAX_EARLIER_CHARS: past either, the oldest go first and a warning
 *   (no message text) is logged.
 *
 * The conversation id is opaque and client-held, so it is never trusted on
 * its own: every read verifies the restaurant id embedded in the cached
 * payload matches the restaurant resolved server-side for this request. A
 * mismatch (or a cold/unknown id) is treated the same as "new conversation"
 * rather than an error — cheap, and it's the only way a client could ever
 * cross a conversation between two restaurants, which this refuses to do.
 */
final class SmartWaiterConversationStore
{
    private const TTL_SECONDS = 1800;
    public const MAX_HISTORY_MESSAGES = 12;
    public const MAX_EARLIER_CUSTOMER_MESSAGES = 20;
    public const MAX_EARLIER_CHARS = 8000;
    private const KEY_PREFIX = 'smart_waiter_conv_';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $smartWaiterLogger,
    ) {}

    public function newConversationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return ChatMessage[] */
    public function getHistory(string $conversationId, int $restaurantId): array
    {
        return array_map(
            static fn (array $m) => new ChatMessage($m['role'], $m['content']),
            $this->load($conversationId, $restaurantId)['messages']
        );
    }

    /** @return list<string> oldest first */
    public function getEarlierCustomerMessages(string $conversationId, int $restaurantId): array
    {
        return $this->load($conversationId, $restaurantId)['earlier_customer_messages'];
    }

    public function append(string $conversationId, int $restaurantId, ChatMessage $userMessage, ChatMessage $assistantMessage): void
    {
        $data = $this->load($conversationId, $restaurantId);
        $messages = $data['messages'];
        $messages[] = ['role' => $userMessage->role, 'content' => $userMessage->content];
        $messages[] = ['role' => $assistantMessage->role, 'content' => $assistantMessage->content];

        $earlier = $data['earlier_customer_messages'];
        if (count($messages) > self::MAX_HISTORY_MESSAGES) {
            $dropped = array_slice($messages, 0, count($messages) - self::MAX_HISTORY_MESSAGES);
            $messages = array_slice($messages, -self::MAX_HISTORY_MESSAGES);
            foreach ($dropped as $m) {
                if ($m['role'] === 'user') {
                    $earlier[] = $m['content'];
                }
            }
        }

        $this->save($conversationId, $restaurantId, $messages, $earlier);
    }

    /**
     * A customer message no provider answered (every one failed): it can't
     * join the recent turns — they must alternate customer/waiter — but it
     * is still part of the conversation, e.g. "I'm allergic to bread".
     */
    public function rememberUnansweredCustomerMessage(string $conversationId, int $restaurantId, ChatMessage $userMessage): void
    {
        $data = $this->load($conversationId, $restaurantId);
        $earlier = $data['earlier_customer_messages'];
        $earlier[] = $userMessage->content;

        $this->save($conversationId, $restaurantId, $data['messages'], $earlier);
    }

    /** @return array{messages: list<array{role: string, content: string}>, earlier_customer_messages: list<string>} */
    private function load(string $conversationId, int $restaurantId): array
    {
        $empty = ['messages' => [], 'earlier_customer_messages' => []];

        $item = $this->cache->getItem($this->key($conversationId));
        if (!$item->isHit()) {
            return $empty;
        }

        $data = $item->get();
        if (!is_array($data) || ($data['restaurant_id'] ?? null) !== $restaurantId) {
            return $empty;
        }

        // A conversation saved before 'earlier_customer_messages' existed has none.
        return [
            'messages' => array_values($data['messages'] ?? []),
            'earlier_customer_messages' => array_values($data['earlier_customer_messages'] ?? []),
        ];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param list<string> $earlier
     */
    private function save(string $conversationId, int $restaurantId, array $messages, array $earlier): void
    {
        $dropped = 0;
        while (count($earlier) > self::MAX_EARLIER_CUSTOMER_MESSAGES
            || ($earlier !== [] && array_sum(array_map('mb_strlen', $earlier)) > self::MAX_EARLIER_CHARS)) {
            array_shift($earlier);
            $dropped++;
        }
        if ($dropped > 0) {
            $this->smartWaiterLogger->warning('Smart Waiter earlier customer messages capped: oldest dropped', [
                'dropped' => $dropped,
                'kept' => count($earlier),
            ]);
        }

        $item = $this->cache->getItem($this->key($conversationId));
        $item->set([
            'restaurant_id' => $restaurantId,
            'messages' => $messages,
            'earlier_customer_messages' => $earlier,
        ]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    private function key(string $conversationId): string
    {
        // PSR-6 keys can't contain {}()/\@: — the id is client-supplied, so
        // strip anything but alphanumerics rather than trusting its shape.
        return self::KEY_PREFIX . preg_replace('/[^a-zA-Z0-9]/', '', $conversationId);
    }
}
