<?php

namespace App\Services\Schedule;

use App\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Resolves nominal shifts into merged calendar windows for one production line.
 */
final class ShiftCalendar
{
    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function windows(int $lineId, CarbonInterface $from, CarbonInterface $until): array
    {
        if ($until->lessThanOrEqualTo($from)) {
            return [];
        }

        $shifts = Shift::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('line_id')->orWhere('line_id', $lineId))
            ->orderBy('sort_order')
            ->get();

        $rangeStart = CarbonImmutable::instance($from)->setTimezone(config('app.timezone'));
        $rangeEnd = CarbonImmutable::instance($until)->setTimezone(config('app.timezone'));
        $cursor = $rangeStart->subDay()->startOfDay();
        $lastDay = $rangeEnd->startOfDay();
        $windows = [];

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $isoWeekday = (int) $cursor->format('N');
            foreach ($shifts as $shift) {
                if (! $this->runsOn($shift, $isoWeekday)) {
                    continue;
                }

                $start = $cursor->setTimeFromTimeString($shift->start_time);
                $end = $cursor->setTimeFromTimeString($shift->end_time);
                if ($end->equalTo($start)) {
                    continue;
                }
                if ($end->lessThan($start)) {
                    $end = $end->addDay();
                }
                if ($end->lessThanOrEqualTo($rangeStart) || $start->greaterThanOrEqualTo($rangeEnd)) {
                    continue;
                }

                $windows[] = [
                    'start' => $start->greaterThan($rangeStart) ? $start : $rangeStart,
                    'end' => $end->lessThan($rangeEnd) ? $end : $rangeEnd,
                ];
            }
            $cursor = $cursor->addDay();
        }

        return $this->merge($windows);
    }

    private function runsOn(Shift $shift, int $isoWeekday): bool
    {
        return ! is_array($shift->days_of_week)
            || in_array($isoWeekday, $shift->days_of_week, true);
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function merge(array $windows): array
    {
        usort(
            $windows,
            fn (array $left, array $right): int => $left['start']->getTimestamp() <=> $right['start']->getTimestamp(),
        );
        $merged = [];

        foreach ($windows as $window) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex < 0 || $window['start']->greaterThan($merged[$lastIndex]['end'])) {
                $merged[] = $window;

                continue;
            }

            if ($window['end']->greaterThan($merged[$lastIndex]['end'])) {
                $merged[$lastIndex]['end'] = $window['end'];
            }
        }

        return $merged;
    }
}
