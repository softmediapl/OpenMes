<?php

namespace Tests\Feature\Services;

use App\Models\Line;
use App\Models\Shift;
use App\Services\Schedule\ShiftCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_merges_overlapping_global_and_line_shifts(): void
    {
        $line = Line::factory()->create();
        $this->shift(null, '06:00', '14:00', [1]);
        $this->shift($line->id, '12:00', '18:00', [1]);

        $windows = app(ShiftCalendar::class)->windows(
            $line->id,
            CarbonImmutable::parse('2026-08-17 00:00'),
            CarbonImmutable::parse('2026-08-18 00:00'),
        );

        $this->assertCount(1, $windows);
        $this->assertSame('2026-08-17 06:00', $windows[0]['start']->format('Y-m-d H:i'));
        $this->assertSame('2026-08-17 18:00', $windows[0]['end']->format('Y-m-d H:i'));
    }

    public function test_it_resolves_an_overnight_shift_from_its_starting_weekday(): void
    {
        $line = Line::factory()->create();
        $this->shift($line->id, '22:00', '06:00', [1]);

        $windows = app(ShiftCalendar::class)->windows(
            $line->id,
            CarbonImmutable::parse('2026-08-17 23:00'),
            CarbonImmutable::parse('2026-08-18 08:00'),
        );

        $this->assertCount(1, $windows);
        $this->assertSame('2026-08-17 23:00', $windows[0]['start']->format('Y-m-d H:i'));
        $this->assertSame('2026-08-18 06:00', $windows[0]['end']->format('Y-m-d H:i'));
    }

    public function test_an_explicitly_empty_day_list_produces_no_windows(): void
    {
        $line = Line::factory()->create();
        $this->shift($line->id, '06:00', '14:00', []);

        $windows = app(ShiftCalendar::class)->windows(
            $line->id,
            CarbonImmutable::parse('2026-08-17 00:00'),
            CarbonImmutable::parse('2026-08-18 00:00'),
        );

        $this->assertSame([], $windows);
    }

    private function shift(?int $lineId, string $start, string $end, ?array $days): Shift
    {
        return Shift::create([
            'name' => "{$start}-{$end}",
            'code' => uniqid('SHIFT-', true),
            'start_time' => $start,
            'end_time' => $end,
            'days_of_week' => $days,
            'line_id' => $lineId,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
