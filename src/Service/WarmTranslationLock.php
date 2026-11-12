<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * One loading screen at a time translating a restaurant + language
 * (MenuController::warmTranslations()): two visits at once would otherwise
 * ask the AI for the same texts. A PostgreSQL advisory lock — no table, no
 * migration, shared by every PHP process, and released by PostgreSQL itself
 * if the process dies mid-way (the connection closes).
 *
 * The second visit waits, checking every POLL_SECONDS, until the first one
 * is done — then it goes on as usual, which only translates what's still
 * missing (nothing, normally; the rest if the first one failed half-way) —
 * or until the TranslationDeadline passes, and then it leaves without
 * calling the AI: the loading screen shows the menu anyway.
 */
final class WarmTranslationLock
{
    public const POLL_SECONDS = 0.25;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {}

    /** True once held; false if the deadline passed while another visit held it. */
    public function acquire(int $restaurantId, string $locale, TranslationDeadline $deadline): bool
    {
        while (!$this->tryAcquire($restaurantId, $locale)) {
            if ($deadline->isPassed()) {
                return false;
            }
            $this->clock->sleep(self::POLL_SECONDS);
        }

        return true;
    }

    public function release(int $restaurantId, string $locale): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_unlock(CAST(? AS int4), CAST(? AS int4))', [$restaurantId, self::localeKey($locale)]);
    }

    private function tryAcquire(int $restaurantId, string $locale): bool
    {
        return (bool) $this->connection->fetchOne('SELECT pg_try_advisory_lock(CAST(? AS int4), CAST(? AS int4))', [$restaurantId, self::localeKey($locale)]);
    }

    /**
     * crc32() is unsigned (0 … 2^32-1) on 64-bit PHP; PostgreSQL's int4 is
     * signed, so the upper half is wrapped to negative.
     */
    public static function localeKey(string $locale): int
    {
        $crc = crc32($locale);

        return $crc > 0x7FFFFFFF ? $crc - 0x100000000 : $crc;
    }
}
