<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\SalaryAdvancePolicy;
use Carbon\Carbon;
use Illuminate\Support\Str;

class SalaryAdvanceService
{
    public function policyForCompany(string $companyId): ?SalaryAdvancePolicy
    {
        return SalaryAdvancePolicy::where('company_id', $companyId)->first();
    }

    public function maxAllowedAmount(Employee $employee, SalaryAdvancePolicy $policy): float
    {
        return round((float) $employee->base_salary * (float) $policy->max_advance_percentage / 100, 2);
    }

    public function findActiveAdvance(string $employeeId, string $companyId, ?string $excludeId = null): ?SalaryAdvance
    {
        return SalaryAdvance::where('employee_id', $employeeId)
            ->where('company_id', $companyId)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->with('installments')
            ->get()
            ->first(fn (SalaryAdvance $advance) => $advance->isActive());
    }

    public function hasActiveAdvance(string $employeeId, string $companyId, ?string $excludeId = null): bool
    {
        return $this->findActiveAdvance($employeeId, $companyId, $excludeId) !== null;
    }

    /**
     * Employee must belong to a department that has an assigned manager.
     */
    public function employeeHasDepartmentManager(Employee $employee): bool
    {
        $employee->loadMissing('department');

        return (bool) ($employee->department?->manager_id);
    }

    /**
     * Re-check policy ceilings and active-advance rules before final HR approval.
     *
     * @return array{ok: bool, message?: string, max_allowed_amount?: float}
     */
    public function validateForApproval(SalaryAdvance $advance): array
    {
        $employee = $advance->employee;
        if (! $employee) {
            return ['ok' => false, 'message' => 'Employee record not found for this advance.'];
        }

        $policy = $this->policyForCompany($advance->company_id);
        if (! $policy) {
            return ['ok' => false, 'message' => 'Salary advance policy is not configured for this company.'];
        }

        $maxAllowed = $this->maxAllowedAmount($employee, $policy);
        if ((float) $advance->requested_amount > $maxAllowed) {
            return [
                'ok' => false,
                'message' => 'Requested amount exceeds the maximum allowed advance amount.',
                'max_allowed_amount' => $maxAllowed,
            ];
        }

        if (! $policy->allow_multiple_active_advances
            && $this->hasActiveAdvance($employee->id, $advance->company_id, $advance->id)
        ) {
            return [
                'ok' => false,
                'message' => 'Cannot approve: employee already has another active salary advance.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * Replace any partial installment rows and regenerate the full schedule.
     */
    public function regenerateInstallments(SalaryAdvance $advance): void
    {
        $advance->installments()->delete();

        $repaymentMonths = (int) $advance->repayment_months;
        $baseInstallment = (float) $advance->monthly_installment;
        $remaining = (float) $advance->requested_amount;

        for ($i = 1; $i <= $repaymentMonths; $i++) {
            $amount = $i === $repaymentMonths ? $remaining : $baseInstallment;
            $remaining -= $amount;

            SalaryAdvanceInstallment::create([
                'id' => Str::uuid()->toString(),
                'salary_advance_id' => $advance->id,
                'due_date' => Carbon::now()->addMonthNoOverflow($i)->startOfMonth()->toDateString(),
                'amount' => round($amount, 2),
                'status' => SalaryAdvanceInstallment::STATUS_PENDING,
            ]);
        }
    }

    /**
     * Mark one installment paid and flip the parent advance to paid_off when done.
     */
    public function markInstallmentPaid(SalaryAdvanceInstallment $installment): SalaryAdvance
    {
        $installment->status = SalaryAdvanceInstallment::STATUS_PAID;
        $installment->paid_at = now();
        $installment->save();

        $advance = $installment->salaryAdvance()->with('installments')->firstOrFail();

        $hasPending = $advance->installments()
            ->where('status', SalaryAdvanceInstallment::STATUS_PENDING)
            ->exists();

        if (! $hasPending && $advance->status === SalaryAdvance::STATUS_APPROVED) {
            $advance->status = SalaryAdvance::STATUS_PAID_OFF;
            $advance->save();
        }

        return $advance->fresh(['installments']);
    }
}
