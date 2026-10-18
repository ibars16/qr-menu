<?php

namespace App\Service;

/**
 * Groups Restaurant::$openingHours for display in the public menu footer:
 * Monday → Sunday, consecutive days with exactly the same hours collapse
 * into one row ("Lun–Vie 13:00–16:00 · 20:00–23:30"). A day that isn't
 * configured is skipped and breaks the run (Lun–Mar, then Jue on its own);
 * there is no wrap-around from Sunday to Monday. Pure (no I/O).
 */
final class OpeningHoursGrouper
{
    /**
     * @param array<string, list<array{0: string, 1: string}>>|null $hours as stored (see ContactDetailsNormalizer)
     *
     * @return list<array{from: string, to: string, closed: bool, shifts: list<array{0: string, 1: string}>}>
     *               from/to are day keys (equal for a single day); shifts is [] when closed
     */
    public function group(?array $hours): array
    {
        $groups = [];
        $previous = null;

        foreach (ContactDetailsNormalizer::DAYS as $day) {
            if (!isset($hours[$day]) || !is_array($hours[$day])) {
                $previous = null;
                continue;
            }

            $shifts = array_values($hours[$day]);
            if ($previous !== null && $groups[$previous]['shifts'] === $shifts) {
                $groups[$previous]['to'] = $day;
                continue;
            }

            $groups[] = ['from' => $day, 'to' => $day, 'closed' => $shifts === [], 'shifts' => $shifts];
            $previous = array_key_last($groups);
        }

        return $groups;
    }
}
