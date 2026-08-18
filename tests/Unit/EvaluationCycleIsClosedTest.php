<?php

namespace Tests\Unit;

use App\Models\EvaluationCycle;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * True unit test: EvaluationCycle::isClosed() only reads the model's own status/end_date
 * attributes - no database row is ever saved. Extends the framework TestCase for the same
 * Carbon/date-cast reason as HolidayOccursOnTest, not for RefreshDatabase or HTTP access.
 */
class EvaluationCycleIsClosedTest extends TestCase
{
    //هل دورة التقييم منتهية حسب الحالة أو تاريخ الانتهاء
    public function test_explicitly_closed_status_is_closed_even_if_end_date_is_in_the_future(): void
    {
        $cycle = new EvaluationCycle([
            'status' => EvaluationCycle::STATUS_CLOSED,
            'start_date' => Carbon::yesterday()->toDateString(),
            'end_date' => Carbon::tomorrow()->toDateString(),
        ]);

        $this->assertTrue($cycle->isClosed());
    }

    public function test_active_cycle_with_a_future_end_date_is_not_closed(): void
    {
        $cycle = new EvaluationCycle([
            'status' => EvaluationCycle::STATUS_ACTIVE,
            'start_date' => Carbon::yesterday()->toDateString(),
            'end_date' => Carbon::tomorrow()->toDateString(),
        ]);

        $this->assertFalse($cycle->isClosed());
    }

    public function test_active_cycle_whose_end_date_already_passed_is_treated_as_closed(): void
    {
        $cycle = new EvaluationCycle([
            'status' => EvaluationCycle::STATUS_ACTIVE,
            'start_date' => Carbon::now()->subDays(10)->toDateString(),
            'end_date' => Carbon::yesterday()->toDateString(),
        ]);

        $this->assertTrue($cycle->isClosed());
    }

    public function test_draft_cycle_with_a_future_end_date_is_not_closed(): void
    {
        $cycle = new EvaluationCycle([
            'status' => EvaluationCycle::STATUS_DRAFT,
            'start_date' => Carbon::tomorrow()->toDateString(),
            'end_date' => Carbon::tomorrow()->addDays(30)->toDateString(),
        ]);

        $this->assertFalse($cycle->isClosed());
    }
}
