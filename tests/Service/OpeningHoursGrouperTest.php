<?php

namespace App\Tests\Service;

use App\Service\OpeningHoursGrouper;
use PHPUnit\Framework\TestCase;

final class OpeningHoursGrouperTest extends TestCase
{
    private function group(?array $hours): array
    {
        return (new OpeningHoursGrouper())->group($hours);
    }

    public function testNothingConfiguredIsEmpty(): void
    {
        self::assertSame([], $this->group(null));
        self::assertSame([], $this->group([]));
    }

    public function testConsecutiveEqualDaysAreGroupedAndClosedDayIsKept(): void
    {
        $lunch = [['13:00', '16:00']];
        $split = [['13:00', '16:00'], ['20:00', '23:30']];
        $late = [['20:00', '01:00']];

        $groups = $this->group([
            'mon' => $lunch, 'tue' => $lunch, 'wed' => $lunch,
            'thu' => $split,
            'fri' => $late, 'sat' => $late,
            'sun' => [],
        ]);

        self::assertSame([
            ['from' => 'mon', 'to' => 'wed', 'closed' => false, 'shifts' => $lunch],
            ['from' => 'thu', 'to' => 'thu', 'closed' => false, 'shifts' => $split],
            ['from' => 'fri', 'to' => 'sat', 'closed' => false, 'shifts' => $late],
            ['from' => 'sun', 'to' => 'sun', 'closed' => true, 'shifts' => []],
        ], $groups);
    }

    public function testUnconfiguredDayIsSkippedAndBreaksTheRun(): void
    {
        $lunch = [['13:00', '16:00']];

        $groups = $this->group(['mon' => $lunch, 'tue' => $lunch, 'thu' => $lunch, 'sun' => $lunch]);

        self::assertSame(
            [['mon', 'tue'], ['thu', 'thu'], ['sun', 'sun']],
            array_map(fn (array $g) => [$g['from'], $g['to']], $groups),
            'no wrap-around from Sunday to Monday either'
        );
    }
}
