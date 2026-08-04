<?php

namespace App\Services;

use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\SalaryRecord;
use App\Models\SalaryRule;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OvertimeService
{
    public function __construct(
        private readonly SalaryService $salaryService,
    ) {
    }
    public function allowsOvertime(string $companyId): bool
    {
        $policy = AttendancePolicy::where('company_id', $companyId)->first();

        // If no attendance policy yet, do not block overtime requests.
        return $policy?->allows_overtime ?? true;
    }

    public function employeeHasDepartmentManager(Employee $employee): bool
    {
        $employee->loadMissing('department');

        return (bool) ($employee->department?->manager_id);
    }

    public function activeRuleFor(string $companyId, string $durationType): ?SalaryRule
    {
        $ruleType = $durationType === OvertimeRequest::DURATION_DAY
            ? 'overtime_day'
            : 'overtime_hour';

        return SalaryRule::where('company_id', $companyId)
            ->where('rule_type', $ruleType)
            ->where('is_active', true)
            ->first();
    }

    public function workHoursPerDay(string $companyId): int
    {
        $policy = AttendancePolicy::where('company_id', $companyId)->first();
        $hours = (int) ($policy?->minimum_daily_hours ?? 8);

        return max(1, $hours);
    }

    /**
     * Compute overtime pay using company salary rules.
     *
     * hour: (base_salary / 30 / work_hours_per_day) * (rule_percent / 100) * units
     * day:  (base_salary / 30) * (rule_percent / 100) * units
     */
    public function calculateAmount(Employee $employee, SalaryRule $rule, float $units): float
    {
        $monthlySalary = (float) $employee->base_salary;
        $rate = $rule->isPercent() ? ((float) $rule->value / 100) : (float) $rule->value;
        $dailyWage = $monthlySalary / 30;

        if ($rule->time_unit === 'hour' || $rule->rule_type === 'overtime_hour') {
            $hourlyWage = $dailyWage / $this->workHoursPerDay($employee->company_id);

            return round($hourlyWage * $rate * $units, 2);
        }

        return round($dailyWage * $rate * $units, 2);
    }

    public function preview(Employee $employee, string $durationType, float $units): array
    {
        $rule = $this->activeRuleFor($employee->company_id, $durationType);

        if (! $rule) {
            return [
                'ok' => false,
                'message' => 'Overtime salary rule is not configured for this duration type.',
            ];
        }

        $unitAmount = $this->calculateAmount($employee, $rule, 1);
        $totalAmount = $this->calculateAmount($employee, $rule, $units);

        return [
            'ok' => true,
            'duration_type' => $durationType,
            'units' => $units,
            'rule_type' => $rule->rule_type,
            'rule_value' => $rule->value,
            'value_type' => $rule->value_type,
            'unit_amount' => $unitAmount,
            'estimated_amount' => $totalAmount,
        ];
    }

    public function rates(Employee $employee): array
    {
        $hourRule = $this->activeRuleFor($employee->company_id, OvertimeRequest::DURATION_HOUR);
        $dayRule = $this->activeRuleFor($employee->company_id, OvertimeRequest::DURATION_DAY);

        return [
            'rate_per_hour' => $hourRule ? $this->calculateAmount($employee, $hourRule, 1) : null,
            'rate_per_day' => $dayRule ? $this->calculateAmount($employee, $dayRule, 1) : null,
            'currency' => $employee->company?->payroll_currency ?? 'SYP',
        ];
    }

    /**
     * Add approved overtime amount onto the employee's salary record for that month.
     */
    public function applyToSalaryRecord(OvertimeRequest $overtime): SalaryRecord
    {
        $employee = $overtime->employee;
        $date = Carbon::parse($overtime->request_date);
        $month = (int) $date->month;
        $year = (int) $date->year;
        $amount = (float) $overtime->calculated_amount;

        $record = SalaryRecord::where('company_id', $overtime->company_id)
            ->where('employee_id', $employee->id)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if (! $record) {
            $base = (float) $employee->base_salary;
            $record = SalaryRecord::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $overtime->company_id,
                'employee_id' => $employee->id,
                'month' => $month,
                'year' => $year,
                'base_salary' => $base,
                'overtime_amount' => $amount,
                'bonus_amount' => 0,
                'late_deduction' => 0,
                'absent_deduction' => 0,
                'loan_deduction' => 0,
                'manual_bonus' => 0,
                'manual_deduction' => 0,
                'net_salary' => $base + $amount,
                'status' => SalaryRecord::STATUS_DRAFT,
            ]);

            return $record;
        }

        if ($this->salaryService->isPaid($record)) {
            throw new \RuntimeException('Cannot apply overtime to a closed salary record for this month.');
        }

        $record->overtime_amount = round((float) $record->overtime_amount + $amount, 2);
        $record->net_salary = $this->salaryService->recalculateNet($record);
        $record->save();

        return $record;
    }
}
