<?php

namespace App\Tests\Service;

use App\Service\AI\ChatMessage;
use App\Service\SmartWaiterConversationStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * SmartWaiterConversationStore's earlier customer messages: what leaves the
 * recent turns, the caps (MAX_EARLIER_CUSTOMER_MESSAGES /
 * MAX_EARLIER_CHARS — oldest dropped, a warning without any message text),
 * restaurant isolation, and a conversation saved in the old format.
 */
final class SmartWaiterConversationStoreTest extends TestCase
{
    private const RESTAURANT = 7;

    private ArrayAdapter $cache;
    private SmartWaiterConversationStore $store;

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $logs = &$this->logs;
        $logger = new class ($logs) extends AbstractLogger {
            public function __construct(private array &$logs) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $this->store = new SmartWaiterConversationStore($this->cache, $logger);
    }

    private function exchange(string $conversationId, string $question, int $restaurantId = self::RESTAURANT): void
    {
        $this->store->append($conversationId, $restaurantId, new ChatMessage('user', $question), new ChatMessage('assistant', 'Respuesta a ' . $question));
    }

    public function testMessagesLeavingTheWindowBecomeEarlierCustomerMessages(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->exchange('conv', 'Pregunta ' . $i);
        }

        self::assertCount(12, $this->store->getHistory('conv', self::RESTAURANT));
        self::assertSame(['Pregunta 0', 'Pregunta 1'], $this->store->getEarlierCustomerMessages('conv', self::RESTAURANT));
        self::assertSame([], $this->logs);
    }

    public function testAtMostTwentyEarlierMessagesTheOldestDroppedWithAWarning(): void
    {
        // 6 in the window + 25 pushed out of it.
        for ($i = 0; $i < 31; $i++) {
            $this->exchange('conv', 'Pregunta ' . $i);
        }

        $earlier = $this->store->getEarlierCustomerMessages('conv', self::RESTAURANT);
        self::assertCount(20, $earlier);
        self::assertSame('Pregunta 5', $earlier[0]);
        self::assertSame('Pregunta 24', $earlier[19]);

        self::assertNotSame([], $this->logs);
        foreach ($this->logs as $log) {
            self::assertSame('warning', $log['level']);
            self::assertStringNotContainsString('Pregunta', json_encode($log), 'never the message text');
        }
    }

    public function testAtMostEightThousandCharactersTheOldestDroppedWithAWarning(): void
    {
        // 15 unanswered messages of 800 characters = 12,000 > 8,000.
        for ($i = 0; $i < 15; $i++) {
            $this->store->rememberUnansweredCustomerMessage('conv', self::RESTAURANT, new ChatMessage('user', sprintf('%02d', $i) . str_repeat('x', 798)));
        }

        $earlier = $this->store->getEarlierCustomerMessages('conv', self::RESTAURANT);
        self::assertSame(10, count($earlier), '10 × 800 = 8,000 fits, one more doesn\'t');
        self::assertStringStartsWith('05', $earlier[0], 'the oldest went first');
        self::assertLessThanOrEqual(SmartWaiterConversationStore::MAX_EARLIER_CHARS, array_sum(array_map('mb_strlen', $earlier)));
        self::assertSame('warning', $this->logs[0]['level']);
        self::assertStringNotContainsString('xxx', json_encode($this->logs));
    }

    public function testAnUnansweredMessageNeverBecomesATurn(): void
    {
        $this->exchange('conv', 'Pregunta 0');
        $this->store->rememberUnansweredCustomerMessage('conv', self::RESTAURANT, new ChatMessage('user', 'Soy alérgico al pan'));

        self::assertSame(['user', 'assistant'], array_map(static fn (ChatMessage $m) => $m->role, $this->store->getHistory('conv', self::RESTAURANT)));
        self::assertSame(['Soy alérgico al pan'], $this->store->getEarlierCustomerMessages('conv', self::RESTAURANT));
    }

    public function testAnotherRestaurantNeverGetsThem(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->exchange('conv', 'Pregunta ' . $i);
        }

        self::assertSame([], $this->store->getEarlierCustomerMessages('conv', 99));
        self::assertSame([], $this->store->getHistory('conv', 99));
    }

    public function testAConversationSavedInTheOldFormatStillWorks(): void
    {
        $item = $this->cache->getItem('smart_waiter_conv_conv');
        $item->set(['restaurant_id' => self::RESTAURANT, 'messages' => [
            ['role' => 'user', 'content' => 'Pregunta vieja'],
            ['role' => 'assistant', 'content' => 'Respuesta vieja'],
        ]]);
        $this->cache->save($item);

        self::assertSame([], $this->store->getEarlierCustomerMessages('conv', self::RESTAURANT));
        self::assertCount(2, $this->store->getHistory('conv', self::RESTAURANT));

        $this->exchange('conv', 'Pregunta nueva');
        self::assertCount(4, $this->store->getHistory('conv', self::RESTAURANT));
    }
}
