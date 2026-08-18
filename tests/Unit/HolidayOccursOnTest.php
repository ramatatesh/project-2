<?php

namespace Tests\Unit;

use App\Models\Holiday;
use Tests\TestCase;

/**
 * True unit test: Holiday::occursOn() is pure date logic on the model's own attributes -
 * no database row is ever saved, no HTTP request is made. This extends the framework's
 * TestCase (not plain PHPUnit\Framework\TestCase) only because the Eloquent date casts
 * on start_date/end_date need Laravel's Date facade to be bootstrapped - it still does not
 * use RefreshDatabase and never touches a real table, so it remains a Unit test, not a
 * Feature test.
 */
class HolidayOccursOnTest extends TestCase
{
    // هل تاريخ معيّن يقع ضمن عطلة، بما فيها العطل المتكررة سنوياً
    public function test_single_day_non_repeating_holiday_matches_only_its_exact_date(): void
    {
        $holiday = new Holiday([
            'name' => 'Company Anniversary',
            'holiday_type' => 'single_day',
            'start_date' => '2026-03-01',
            'end_date' => null,
            'repeats_annually' => false,
        ]);

        $this->assertTrue($holiday->occursOn('2026-03-01'));
        $this->assertFalse($holiday->occursOn('2026-03-02'));
        $this->assertFalse($holiday->occursOn('2027-03-01'));
    }

    public function test_multi_day_non_repeating_holiday_matches_the_whole_range(): void
    {
        $holiday = new Holiday([
            'name' => 'Eid Break',
            'holiday_type' => 'range',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-13',
            'repeats_annually' => false,
        ]);

        $this->assertTrue($holiday->occursOn('2026-04-10'));
        $this->assertTrue($holiday->occursOn('2026-04-12'));
        $this->assertTrue($holiday->occursOn('2026-04-13'));
        $this->assertFalse($holiday->occursOn('2026-04-14'));
    }

    public function test_annually_repeating_holiday_matches_the_same_month_and_day_every_year(): void
    {
        $holiday = new Holiday([
            'name' => 'Independence Day',
            'holiday_type' => 'single_day',
            'start_date' => '2020-04-17',
            'end_date' => null,
            'repeats_annually' => true,
        ]);

        $this->assertTrue($holiday->occursOn('2026-04-17'));
        $this->assertTrue($holiday->occursOn('2030-04-17'));
        $this->assertFalse($holiday->occursOn('2026-04-18'));
        $this->assertFalse($holiday->occursOn('2026-05-17'));
    }
}
