<?php

namespace Tests\Unit;

use App\Models\SalaryRecord;
use App\Services\SalaryService;
use PHPUnit\Framework\TestCase;

/**
 * True unit test: every method here only reads attributes already present on an in-memory
 * SalaryRecord (never saved, never queried) - no database, no HTTP. This is the core
 * "how much does the employee actually get paid" math for the whole payroll feature.
 */
class SalaryServiceCalculationsTest extends TestCase
{
    private function record(array $overrides = []): SalaryRecord
    {
        return new SalaryRecord(array_merge([
            'base_salary' => 1000,
            'overtime_amount' => 0,
            'bonus_amount' => 0,
            'manual_bonus' => 0,
            'late_deduction' => 0,
            'absent_deduction' => 0,
            'loan_deduction' => 0,
            'manual_deduction' => 0,
            'status' => SalaryRecord::STATUS_DRAFT,
        ], $overrides));
    }

    public function test_recalculate_net_sums_additions_and_subtracts_deductions_from_the_base(): void
    {
        $service = new SalaryService();

        $record = $this->record([
            'base_salary' => 1000,
            'overtime_amount' => 50,
            'bonus_amount' => 100,
            'manual_bonus' => 25,
            'late_deduction' => 15,
            'absent_deduction' => 20,
            'loan_deduction' => 40,
            'manual_deduction' => 0,
        ]);

        // 1000 + 50 + 100 + 25 - 15 - 20 - 40 - 0 = 1100
        $this->assertSame(1100.0, $service->recalculateNet($record));
    }

    public function test_total_additions_sums_only_the_addition_fields(): void
    {
        $service = new SalaryService();

        $record = $this->record([
            'overtime_amount' => 50,
            'bonus_amount' => 100,
            'manual_bonus' => 25,
            'late_deduction' => 999, // must be ignored
        ]);

        $this->assertSame(175.0, $service->totalAdditions($record));
    }

    public function test_total_deductions_sums_only_the_deduction_fields(): void
    {
        $service = new SalaryService();

        $record = $this->record([
            'late_deduction' => 15,
            'absent_deduction' => 20,
            'loan_deduction' => 40,
            'manual_deduction' => 5,
            'bonus_amount' => 999, // must be ignored
        ]);

        $this->assertSame(80.0, $service->totalDeductions($record));
    }

    public function test_payment_summary_classifies_a_record_with_no_adjustments_as_full(): void
    {
        $service = new SalaryService();

        $this->assertSame('full', $service->paymentSummary($this->record()));
    }

    public function test_payment_summary_classifies_additions_only(): void
    {
        $service = new SalaryService();

        $record = $this->record(['bonus_amount' => 100]);

        $this->assertSame('with_additions', $service->paymentSummary($record));
    }

    public function test_payment_summary_classifies_deductions_only(): void
    {
        $service = new SalaryService();

        $record = $this->record(['late_deduction' => 10]);

        $this->assertSame('with_deductions', $service->paymentSummary($record));
    }

    public function test_payment_summary_classifies_both_additions_and_deductions(): void
    {
        $service = new SalaryService();

        $record = $this->record(['bonus_amount' => 100, 'late_deduction' => 10]);

        $this->assertSame('with_additions_and_deductions', $service->paymentSummary($record));
    }

    public function test_is_paid_is_true_for_paid_and_closed_statuses_only(): void
    {
        $service = new SalaryService();

        $this->assertTrue($service->isPaid($this->record(['status' => SalaryRecord::STATUS_PAID])));
        $this->assertTrue($service->isPaid($this->record(['status' => SalaryRecord::STATUS_CLOSED])));
        $this->assertFalse($service->isPaid($this->record(['status' => SalaryRecord::STATUS_DRAFT])));
    }
}
