<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\SalaryAdvancePolicy;
use App\Services\SalaryAdvanceService;
use PHPUnit\Framework\TestCase;

/**
 * True unit test: maxAllowedAmount() only reads employee.base_salary and
 * policy.max_advance_percentage, both already in memory - no database, no HTTP.
 */
class SalaryAdvanceServiceMaxAmountTest extends TestCase
{
    public function test_max_allowed_amount_is_the_configured_percentage_of_the_base_salary(): void
    {
        $service = new SalaryAdvanceService();

        $employee = new Employee(['base_salary' => 2000]);
        $policy = new SalaryAdvancePolicy(['max_advance_percentage' => 30]);

        // 2000 * 30% = 600
        $this->assertSame(600.0, $service->maxAllowedAmount($employee, $policy));
    }

    public function test_zero_percent_policy_allows_no_advance(): void
    {
        $service = new SalaryAdvanceService();

        $employee = new Employee(['base_salary' => 1500]);
        $policy = new SalaryAdvancePolicy(['max_advance_percentage' => 0]);

        $this->assertSame(0.0, $service->maxAllowedAmount($employee, $policy));
    }

    public function test_result_is_rounded_to_two_decimal_places(): void
    {
        $service = new SalaryAdvanceService();

        // 333 * 33.33% = 110.9889, which does not terminate at 2 decimals on its own -
        // the rounding under test happens in maxAllowedAmount() itself, not in the input.
        $employee = new Employee(['base_salary' => 333]);
        $policy = new SalaryAdvancePolicy(['max_advance_percentage' => 33.33]);

        $this->assertEqualsWithDelta(110.99, $service->maxAllowedAmount($employee, $policy), 0.001);
    }
}
