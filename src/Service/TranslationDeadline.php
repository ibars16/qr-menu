<?php

namespace App\Service;

use Psr\Clock\ClockInterface;

/**
 * Until when the loading screen's translation (MenuController::
 * warmTranslations()) may START a new AI batch. The screen itself waits
 * 25 s (menu/loading.html.twig) and then shows the menu anyway; a batch
 * started later would only finish after the customer has left. Whatever
 * isn't done by then is still missing, so the next visit's loading screen
 * does it — never what was already stored.
 *
 * Read through the clock (symfony/clock), so a test can move time on with
 * a MockClock instead of waiting.
 */
final class TranslationDeadline
{
    /** No new batch after this many seconds of the loading screen's request. */
    public const WARM_SECONDS = 20.0;

    private function __construct(
        private readonly ClockInterface     $clock,
        private readonly \DateTimeImmutable $at,
    ) {}

    public static function in(ClockInterface $clock, float $seconds): self
    {
        return new self($clock, $clock->now()->modify(sprintf('+%d milliseconds', (int) round($seconds * 1000))));
    }

    public function isPassed(): bool
    {
        return $this->clock->now() >= $this->at;
    }
}
