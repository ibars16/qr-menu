<?php

namespace App\Service;

use App\Service\AI\ChatMessage;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Short-term memory for one chat session — cache only, sliding TTL, capped
 * length. There is deliberately no database table backing this: closing the
 * tab (sessionStorage on the client) or letting the TTL lapse is the only
 * way a conversation ever ends, and either way nothing durable is left
 * behind. See SmartWaiterExchangeLog for what *is* kept permanently
 * (aggregated, no message content).
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
    private const MAX_HISTORY_MESSAGES = 12;
    private const KEY_PREFIX = 'smart_waiter_conv_';

    public function __construct(private readonly CacheItemPoolInterface $cache) {}

    public function newConversationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return ChatMessage[] */
    public function getHistory(string $conversationId, int $restaurantId): array
    {
        $item = $this->cache->getItem($this->key($conversationId));
        if (!$item->isHit()) {
            return [];
        }

        $data = $item->get();
        if (!is_array($data) || ($data['restaurant_id'] ?? null) !== $restaurantId) {
            return [];
        }

        return array_map(
            static fn (array $m) => new ChatMessage($m['role'], $m['content']),
            $data['messages'] ?? []
        );
    }

    public function append(string $conversationId, int $restaurantId, ChatMessage $userMessage, ChatMessage $assistantMessage): void
    {
        $history = $this->getHistory($conversationId, $restaurantId);
        $history[] = $userMessage;
        $history[] = $assistantMessage;

        if (count($history) > self::MAX_HISTORY_MESSAGES) {
            $history = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        }

        $item = $this->cache->getItem($this->key($conversationId));
        $item->set([
            'restaurant_id' => $restaurantId,
            'messages' => array_map(
                static fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content],
                $history
            ),
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
