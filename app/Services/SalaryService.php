<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EvaluationPolicy;
use App\Models\EvaluationScore;
use App\Models\OvertimeRequest;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\SalaryRecord;
use App\Models\SalaryRule;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class SalaryService
{
    /** Official payroll cutoff day — salaries can be marked paid from this day onward. */
    public const PAYROLL_CUTOFF_DAY = 15;

    public function recalculateNet(SalaryRecord $record): float
    {
        return round(
            (float) $record->base_salary
            + (float) $record->overtime_amount
            + (float) $record->bonus_amount
            + (float) $record->manual_bonus
            - (float) $record->late_deduction
            - (float) $record->absent_deduction
            - (float) $record->loan_deduction
            - (float) $record->manual_deduction,
            2
        );
    }

    public function totalAdditions(SalaryRecord $record): float
    {
        return round(
            (float) $record->overtime_amount
            + (float) $record->bonus_amount
            + (float) $record->manual_bonus,
            2
        );
    }

    public function totalDeductions(SalaryRecord $record): float
    {
        return round(
            (float) $record->late_deduction
            + (float) $record->absent_deduction
            + (float) $record->loan_deduction
            + (float) $record->manual_deduction,
            2
        );
    }

    /**
     * full | with_additions | with_deductions | with_additions_and_deductions
     */
    public function paymentSummary(SalaryRecord $record): string
    {
        $hasAdditions = $this->totalAdditions($record) > 0;
        $hasDeductions = $this->totalDeductions($record) > 0;

        if ($hasAdditions && $hasDeductions) {
            return 'with_additions_and_deductions';
        }

        if ($hasAdditions) {
            return 'with_additions';
        }

        if ($hasDeductions) {
            return 'with_deductions';
        }

        return 'full';
    }

    public function isPaid(SalaryRecord $record): bool
    {
        return in_array($record->status, [SalaryRecord::STATUS_PAID, SalaryRecord::STATUS_CLOSED], true);
    }

    public function lastReceived(Employee $employee): ?SalaryRecord
    {
        return SalaryRecord::where('employee_id', $employee->id)
            ->where('company_id', $employee->company_id)
            ->whereIn('status', [SalaryRecord::STATUS_PAID, SalaryRecord::STATUS_CLOSED])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('closed_at')
            ->first();
    }

    /**
     * True when the payroll period already has any paid/closed salary rows.
     */
    public function periodIsLocked(string $companyId, int $month, int $year): bool
    {
        return SalaryRecord::where('company_id', $companyId)
            ->where('month', $month)
            ->where('year', $year)
            ->whereIn('status', [SalaryRecord::STATUS_PAID, SalaryRecord::STATUS_CLOSED])
            ->exists();
    }

    /**
     * Period metadata for the HR UI (enable/disable Generate).
     */
    public function periodStatus(string $companyId, int $month, int $year): array
    {
        $total = SalaryRecord::where('company_id', $companyId)
            ->where('month', $month)
            ->where('year', $year)
            ->count();

        $paid = SalaryRecord::where('company_id', $companyId)
            ->where('month', $month)
            ->where('year', $year)
            ->whereIn('status', [SalaryRecord::STATUS_PAID, SalaryRecord::STATUS_CLOSED])
            ->count();

        $draft = SalaryRecord::where('company_id', $companyId)
            ->where('month', $month)
            ->where('year', $year)
            ->where('status', SalaryRecord::STATUS_DRAFT)
            ->count();

        $locked = $paid > 0;
        $today = now()->startOfDay();
        $isCurrentPeriod = (int) $today->month === $month && (int) $today->year === $year;
        $cutoffDate = Carbon::create($year, $month, self::PAYROLL_CUTOFF_DAY)->startOfDay();
        $pastCutoff = $today->gte($cutoffDate);

        $status = 'empty';
        if ($locked && $draft === 0) {
            $status = 'paid';
        } elseif ($draft > 0) {
            $status = 'draft';
        } elseif ($paid > 0) {
            $status = 'paid';
        }

        return [
            'month' => $month,
            'year' => $year,
            'period' => sprintf('%04d-%02d', $year, $month),
            'cutoff_day' => self::PAYROLL_CUTOFF_DAY,
            'cutoff_date' => $cutoffDate->toDateString(),
            'status' => $status,
            'can_generate' => ! $locked,
            'can_pay' => $draft > 0 && $pastCutoff,
            'is_locked' => $locked,
            'is_current_period' => $isCurrentPeriod,
            'past_cutoff' => $pastCutoff,
            'totals' => [
                'records' => $total,
                'draft' => $draft,
                'paid' => $paid,
            ],
        ];
    }

    /**
     * Run the payroll calculation engine for a company period.
     * Creates/updates draft rows; never touches paid/closed periods.
     *
     * @return array{created: int, updated: int, skipped_paid: int, as_of_date: string}
     */
    public function generatePayroll(string $companyId, int $month, int $year, ?string $employeeId = null): array
    {
        if ($this->periodIsLocked($companyId, $month, $year)) {
            throw new RuntimeException('Cannot recalculate a closed or paid payroll period.');
        }

        $asOf = $this->resolveAsOfDate($month, $year);
        $created = 0;
        $updated = 0;

        $query = Employee::where('company_id', $companyId)->where('is_active', true);
        if ($employeeId) {
            $query->where('id', $employeeId);
        }

        $query->orderBy('id')->chunkById(100, function ($employees) use ($month, $year, $asOf, &$created, &$updated) {
            foreach ($employees as $employee) {
                $existing = SalaryRecord::where('employee_id', $employee->id)
                    ->where('month', $month)
                    ->where('year', $year)
                    ->first();

                $wasNew = $existing === null;
                $this->calculateDraftRecord($employee, $month, $year, $asOf);
                $wasNew ? $created++ : $updated++;
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped_paid' => 0,
            'as_of_date' => $asOf->toDateString(),
        ];
    }

    /**
     * Payroll Calculation Engine for one employee:
     * contract (base) + attendance penalties + approved overtime + advance installments + evaluation.
     * Manual HR adjustments are preserved across recalculations.
     */
    public function calculateDraftRecord(
        Employee $employee,
        int $month,
        int $year,
        ?Carbon $asOfDate = null,
    ): SalaryRecord {
        $asOfDate ??= $this->resolveAsOfDate($month, $year);

        $record = SalaryRecord::where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if ($record && $this->isPaid($record)) {
            throw new RuntimeException('Cannot recalculate a closed or paid payroll period.');
        }

        $base = (float) $employee->base_salary;
        $overtime = $this->sumApprovedOvertime($employee->id, $month, $year, $asOfDate);
        $attendance = $this->calculateAttendanceDeductions($employee, $month, $year, $asOfDate);
        $loanDeduction = $this->sumAdvanceInstallmentsDue($employee->id, $month, $year, $asOfDate);
        $evaluation = $this->resolveEvaluationAdjustment($employee);

        $preservedManualBonus = 0.0;
        $preservedManualDeduction = 0.0;
        if ($record) {
            $preservedManualBonus = max(
                0,
                (float) $record->manual_bonus - (float) ($record->evaluation_bonus_amount ?? 0)
            );
            $preservedManualDeduction = max(
                0,
                (float) $record->manual_deduction - (float) ($record->evaluation_deduction_amount ?? 0)
            );
            $nonEvalBonus = max(
                0,
                (float) $record->bonus_amount - (float) ($record->evaluation_bonus_amount ?? 0)
            );
            $preservedManualBonus += $nonEvalBonus;
        }

        $payload = [
            'base_salary' => $base,
            'overtime_amount' => $overtime,
            'bonus_amount' => $evaluation['bonus'],
            'late_deduction' => $attendance['late'],
            'absent_deduction' => $attendance['absent'] + $attendance['early'],
            'loan_deduction' => $loanDeduction,
            'manual_bonus' => $preservedManualBonus,
            'manual_deduction' => round($preservedManualDeduction + $evaluation['deduction'], 2),
            'evaluation_bonus_amount' => $evaluation['bonus'],
            'evaluation_deduction_amount' => $evaluation['deduction'],
            'status' => SalaryRecord::STATUS_DRAFT,
        ];

        if (! $record) {
            $record = SalaryRecord::create(array_merge($payload, [
                'id' => Str::uuid()->toString(),
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'month' => $month,
                'year' => $year,
                'net_salary' => 0,
            ]));
        } else {
            $record->fill($payload);
        }

        $record->net_salary = $this->recalculateNet($record);
        $record->save();

        return $record->fresh('salaryAdjustments');
    }

    /**
     * @deprecated use calculateDraftRecord / generatePayroll
     */
    public function ensureDraftRecord(Employee $employee, int $month, int $year): SalaryRecord
    {
        return $this->calculateDraftRecord($employee, $month, $year);
    }

    public function markPaid(SalaryRecord $record, string $closedByUserId): SalaryRecord
    {
        if ($this->isPaid($record)) {
            return $record;
        }

        $this->assertPeriodPayable((int) $record->month, (int) $record->year);

        $record->status = SalaryRecord::STATUS_PAID;
        $record->closed_by = $closedByUserId;
        $record->closed_at = now();
        $record->net_salary = $this->recalculateNet($record);
        $record->save();

        return $record->fresh('salaryAdjustments');
    }

    /**
     * Mark all draft salary records for a company period as paid.
     *
     * @return array{paid: int, already_paid: int, total: int}
     */
    public function payPeriod(string $companyId, int $month, int $year, string $closedByUserId): array
    {
        $this->assertPeriodPayable($month, $year);

        $records = SalaryRecord::where('company_id', $companyId)
            ->where('month', $month)
            ->where('year', $year)
            ->get();

        if ($records->isEmpty()) {
            throw new RuntimeException('No salary records found for this period. Run generate first.');
        }

        $paid = 0;
        $alreadyPaid = 0;

        foreach ($records as $record) {
            if ($this->isPaid($record)) {
                $alreadyPaid++;
                continue;
            }

            // assert already passed for the period; mark without re-checking per row
            $record->status = SalaryRecord::STATUS_PAID;
            $record->closed_by = $closedByUserId;
            $record->closed_at = now();
            $record->net_salary = $this->recalculateNet($record);
            $record->save();
            $paid++;
        }

        return [
            'paid' => $paid,
            'already_paid' => $alreadyPaid,
            'total' => $records->count(),
        ];
    }

    /**
     * Payment is allowed only on/after the official cutoff day of that period.
     */
    public function assertPeriodPayable(int $month, int $year): void
    {
        $cutoff = Carbon::create($year, $month, self::PAYROLL_CUTOFF_DAY)->startOfDay();

        if (now()->startOfDay()->lt($cutoff)) {
            throw new RuntimeException(
                __('Cannot mark payroll as paid before the cutoff day (:day). Payment opens on :date.', [
                    'day' => self::PAYROLL_CUTOFF_DAY,
                    'date' => $cutoff->toDateString(),
                ])
            );
        }
    }

    public function serializeSummary(SalaryRecord $record): array
    {
        $additions = $this->totalAdditions($record);
        $deductions = $this->totalDeductions($record);

        return [
            'id' => $record->id,
            'month' => (int) $record->month,
            'year' => (int) $record->year,
            'period' => sprintf('%04d-%02d', $record->year, $record->month),
            'base_salary' => (float) $record->base_salary,
            'total_additions' => $additions,
            'total_deductions' => $deductions,
            'net_salary' => (float) $record->net_salary,
            'status' => $record->status,
            'is_received' => $this->isPaid($record),
            'payment_summary' => $this->paymentSummary($record),
            'received_at' => $record->closed_at?->toDateTimeString(),
        ];
    }

    public function serializeDetails(SalaryRecord $record): array
    {
        $summary = $this->serializeSummary($record);
        $additions = [];
        $deductions = [];

        if ((float) $record->overtime_amount > 0) {
            $additions[] = [
                'type' => 'overtime',
                'label' => 'Overtime',
                'amount' => (float) $record->overtime_amount,
            ];
        }
        if ((float) $record->bonus_amount > 0) {
            $additions[] = [
                'type' => 'bonus',
                'label' => 'Bonus',
                'amount' => (float) $record->bonus_amount,
            ];
        }
        if ((float) $record->manual_bonus > 0) {
            $additions[] = [
                'type' => 'manual_bonus',
                'label' => 'Manual bonus',
                'amount' => (float) $record->manual_bonus,
            ];
        }

        if ((float) $record->late_deduction > 0) {
            $deductions[] = [
                'type' => 'late',
                'label' => 'Late deduction',
                'amount' => (float) $record->late_deduction,
            ];
        }
        if ((float) $record->absent_deduction > 0) {
            $deductions[] = [
                'type' => 'absence',
                'label' => 'Absence / early-leave deduction',
                'amount' => (float) $record->absent_deduction,
            ];
        }
        if ((float) $record->loan_deduction > 0) {
            $deductions[] = [
                'type' => 'advance',
                'label' => 'Salary advance installment',
                'amount' => (float) $record->loan_deduction,
            ];
        }
        if ((float) $record->manual_deduction > 0) {
            $deductions[] = [
                'type' => 'manual_deduction',
                'label' => 'Manual deduction',
                'amount' => (float) $record->manual_deduction,
            ];
        }

        $record->loadMissing('salaryAdjustments');
        foreach ($record->salaryAdjustments as $adjustment) {
            $item = [
                'type' => $adjustment->type,
                'label' => $adjustment->description,
                'amount' => abs((float) $adjustment->amount),
            ];

            if ((float) $adjustment->amount >= 0) {
                $additions[] = $item;
            } else {
                $deductions[] = $item;
            }
        }

        return array_merge($summary, [
            'components' => [
                'base_salary' => (float) $record->base_salary,
                'overtime_amount' => (float) $record->overtime_amount,
                'bonus_amount' => (float) $record->bonus_amount,
                'manual_bonus' => (float) $record->manual_bonus,
                'late_deduction' => (float) $record->late_deduction,
                'absent_deduction' => (float) $record->absent_deduction,
                'loan_deduction' => (float) $record->loan_deduction,
                'manual_deduction' => (float) $record->manual_deduction,
            ],
            'additions' => $additions,
            'deductions' => $deductions,
            'adjustments' => $record->salaryAdjustments->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'description' => $a->description,
                'amount' => (float) $a->amount,
            ])->values(),
        ]);
    }

    /**
     * Data cutoff for calculation: today if generating the current month early,
     * otherwise end of the target month.
     */
    private function resolveAsOfDate(int $month, int $year): Carbon
    {
        $endOfMonth = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        $today = now()->startOfDay();

        if ((int) $today->month === $month && (int) $today->year === $year && $today->lt($endOfMonth)) {
            return $today->copy();
        }

        return $endOfMonth;
    }

    private function sumApprovedOvertime(string $employeeId, int $month, int $year, Carbon $asOf): float
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $end = $asOf->toDateString();

        return (float) OvertimeRequest::where('employee_id', $employeeId)
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereDate('request_date', '>=', $start)
            ->whereDate('request_date', '<=', $end)
            ->sum('calculated_amount');
    }

    /**
     * @return array{late: float, absent: float, early: float}
     */
    private function calculateAttendanceDeductions(
        Employee $employee,
        int $month,
        int $year,
        Carbon $asOf,
    ): array {
        $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $end = $asOf->toDateString();
        $base = (float) $employee->base_salary;

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->where('company_id', $employee->company_id)
            ->whereDate('work_date', '>=', $start)
            ->whereDate('work_date', '<=', $end)
            ->get(['attendance_type']);

        $lateDays = $records->where('attendance_type', AttendanceRecord::TYPE_LATE)->count();
        $absentDays = $records->where('attendance_type', AttendanceRecord::TYPE_ABSENT)->count();
        $earlyDays = $records->where('attendance_type', AttendanceRecord::TYPE_EARLY_LEAVE)->count();

        $rules = SalaryRule::where('company_id', $employee->company_id)
            ->where('is_active', true)
            ->whereIn('rule_type', ['late', 'absence', 'early', 'early_leave'])
            ->get()
            ->keyBy('rule_type');

        $lateRule = $rules->get('late');
        $absenceRule = $rules->get('absence');
        $earlyRule = $rules->get('early') ?? $rules->get('early_leave');

        return [
            'late' => $lateRule && $lateDays > 0 ? round($lateRule->calculate($base, $lateDays), 2) : 0.0,
            'absent' => $absenceRule && $absentDays > 0 ? round($absenceRule->calculate($base, $absentDays), 2) : 0.0,
            'early' => $earlyRule && $earlyDays > 0 ? round($earlyRule->calculate($base, $earlyDays), 2) : 0.0,
        ];
    }

    private function sumAdvanceInstallmentsDue(
        string $employeeId,
        int $month,
        int $year,
        ?Carbon $asOf = null,
    ): float {
        $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $end = ($asOf ?? Carbon::create($year, $month, 1)->endOfMonth())->toDateString();

        $advanceIds = SalaryAdvance::where('employee_id', $employeeId)
            ->whereIn('status', [SalaryAdvance::STATUS_APPROVED, SalaryAdvance::STATUS_PAID_OFF])
            ->pluck('id');

        if ($advanceIds->isEmpty()) {
            return 0.0;
        }

        return (float) SalaryAdvanceInstallment::whereIn('salary_advance_id', $advanceIds)
            ->whereBetween('due_date', [$start, $end])
            ->where('status', SalaryAdvanceInstallment::STATUS_PENDING)
            ->sum('amount');
    }

    /**
     * @return array{bonus: float, deduction: float}
     */
    private function resolveEvaluationAdjustment(Employee $employee): array
    {
        $none = ['bonus' => 0.0, 'deduction' => 0.0];

        $policy = EvaluationPolicy::where('company_id', $employee->company_id)->first();

        if (! $policy || ! $policy->apply_review_to_salary) {
            return $none;
        }

        $score = EvaluationScore::where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('status', EvaluationScore::STATUS_FINALIZED)
            ->whereNotNull('final_score')
            ->whereNotNull('finalized_at')
            ->orderByDesc('finalized_at')
            ->first();

        if (! $score) {
            return $none;
        }

        $finalScore = (float) $score->final_score;
        $base = (float) $employee->base_salary;
        $percent = 0.0;
        $kind = null;

        if ($finalScore >= 8) {
            $percent = (float) ($policy->excellent_bonus_percent ?? 0);
            $kind = 'bonus';
        } elseif ($finalScore >= 6) {
            $percent = (float) ($policy->good_bonus_percent ?? 0);
            $kind = 'bonus';
        } elseif ($finalScore >= 4) {
            return $none;
        } else {
            $percent = (float) ($policy->poor_deduction_percent ?? 0);
            $kind = 'deduction';
        }

        if ($percent <= 0) {
            return $none;
        }

        $amount = round($base * $percent / 100, 2);

        if ($kind === 'bonus') {
            return ['bonus' => $amount, 'deduction' => 0.0];
        }

        return ['bonus' => 0.0, 'deduction' => $amount];
    }

    /**
     * Add a manual adjustment (addition/deduction) to a salary record.
     */
    public function addAdjustment(SalaryRecord $record, array $data, string $createdById): SalaryRecord
    {
        $type = $data['type'];
        $amount = (float) $data['amount'];
        $reason = $data['reason'] ?? 'Manual adjustment';

        if ($type === 'addition') {
            $record->manual_bonus = round((float) $record->manual_bonus + $amount, 2);
        } else {
            $record->manual_deduction = round((float) $record->manual_deduction + $amount, 2);
        }

        $record->salaryAdjustments()->create([
            'id' => Str::uuid()->toString(),
            'company_id' => $record->company_id,
            'type' => $type,
            'amount' => $type === 'deduction' ? -$amount : $amount,
            'description' => $reason,
            'created_by' => $createdById,
        ]);

        $record->net_salary = $this->recalculateNet($record);
        $record->save();

        return $record->fresh('salaryAdjustments');
    }
}
