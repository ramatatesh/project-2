<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EvaluationPolicy;
use App\Models\EvaluationScore;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\SalaryRecord;
use Carbon\Carbon;
use Illuminate\Support\Str;

class SalaryService
{
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
     * Ensure a draft salary row exists for employee + month/year.
     * Pulls advance installments due that month into loan_deduction.
     * Applies finalized evaluation bonus/deduction when the company policy enables it.
     */
    public function ensureDraftRecord(Employee $employee, int $month, int $year): SalaryRecord
    {
        $record = SalaryRecord::where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if ($record && $this->isPaid($record)) {
            return $record;
        }

        $loanDeduction = $this->sumAdvanceInstallmentsDue($employee->id, $month, $year);
        $evaluation = $this->resolveEvaluationAdjustment($employee);

        if (! $record) {
            $base = (float) $employee->base_salary;
            $record = SalaryRecord::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'month' => $month,
                'year' => $year,
                'base_salary' => $base,
                'overtime_amount' => 0,
                'bonus_amount' => $evaluation['bonus'],
                'late_deduction' => 0,
                'absent_deduction' => 0,
                'loan_deduction' => $loanDeduction,
                'manual_bonus' => 0,
                'manual_deduction' => $evaluation['deduction'],
                'evaluation_bonus_amount' => $evaluation['bonus'],
                'evaluation_deduction_amount' => $evaluation['deduction'],
                'net_salary' => 0,
                'status' => SalaryRecord::STATUS_DRAFT,
            ]);
            $record->net_salary = $this->recalculateNet($record);
            $record->save();
        } else {
            $record->loan_deduction = $loanDeduction;
            $this->replaceEvaluationAdjustment($record, $evaluation['bonus'], $evaluation['deduction']);
            $record->net_salary = $this->recalculateNet($record);
            $record->save();
        }

        return $record->fresh('salaryAdjustments');
    }

    public function markPaid(SalaryRecord $record, string $closedByUserId): SalaryRecord
    {
        if ($this->isPaid($record)) {
            return $record;
        }

        $record->status = SalaryRecord::STATUS_PAID;
        $record->closed_by = $closedByUserId;
        $record->closed_at = now();
        $record->net_salary = $this->recalculateNet($record);
        $record->save();

        return $record->fresh('salaryAdjustments');
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
                'label' => 'Absence deduction',
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

    private function sumAdvanceInstallmentsDue(string $employeeId, int $month, int $year): float
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

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

    private function replaceEvaluationAdjustment(SalaryRecord $record, float $bonus, float $deduction): void
    {
        $previousBonus = (float) ($record->evaluation_bonus_amount ?? 0);
        $previousDeduction = (float) ($record->evaluation_deduction_amount ?? 0);

        $record->bonus_amount = round((float) $record->bonus_amount - $previousBonus + $bonus, 2);
        $record->manual_deduction = round((float) $record->manual_deduction - $previousDeduction + $deduction, 2);
        $record->evaluation_bonus_amount = $bonus;
        $record->evaluation_deduction_amount = $deduction;
    }

    /**
     * Add a manual adjustment (addition/deduction) to a salary record.
     */
    public function addAdjustment(SalaryRecord $record, array $data, string $createdById): SalaryRecord
    {
        $type = $data['type']; // 'addition' or 'deduction'
        $amount = (float) $data['amount'];
        $reason = $data['reason'] ?? 'Manual adjustment';

        // 1. تحديث الحقول التراكمية المباشرة في جدول الرواتب
        if ($type === 'addition') {
            $record->manual_bonus = round((float) $record->manual_bonus + $amount, 2);
        } else {
            $record->manual_deduction = round((float) $record->manual_deduction + $amount, 2);
        }

        // 2. إنشاء سجل تفصيلي لعملية التدقيق (Audit Trail)
        $record->salaryAdjustments()->create([
            'id' => Str::uuid()->toString(),
            'company_id' => $record->company_id,
            'type' => $type,
            'amount' => $type === 'deduction' ? -$amount : $amount, // يُخزن كقيمة سالبة للخصم إن كان جدول التعديلات يعتمد ذلك
            'description' => $reason,
            'created_by' => $createdById,
        ]);

        // 3. إعادة احتساب الصافي والحفظ
        $record->net_salary = $this->recalculateNet($record);
        $record->save();

        return $record->fresh('salaryAdjustments');
    }
}
