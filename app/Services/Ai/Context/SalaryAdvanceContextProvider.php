<?php

namespace App\Services\Ai\Context;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\User;
use App\Services\Ai\Concerns\MatchesAssistantTopics;
use App\Services\SalaryAdvanceService;

/**
 * Read-only salary advances for the authenticated employee.
 * Avoids touching non-existent updated_at; uses created_at cast only.
 */
class SalaryAdvanceContextProvider implements EmployeeContextProvider
{
    use MatchesAssistantTopics;

    public function __construct(
        private readonly SalaryAdvanceService $salaryAdvanceService,
    ) {}

    public function key(): string
    {
        return 'salary_advances';
    }

    public function supports(string $message): bool
    {
        return $this->matchesAny($message, [
            'سلفة', 'سلف', 'سلفي', 'advance', 'advances',
            'قسط', 'أقساط', 'اقساط', 'installment', 'loan',
            'سداد', 'دفعت', 'متبقي علي',
        ]);
    }

    public function build(Employee $employee, User $user): array
    {
        $companyId = $employee->company_id;

        $advances = SalaryAdvance::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['installments' => function ($query) {
                $query->orderBy('due_date');
            }])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $active = $this->salaryAdvanceService->findActiveAdvance($employee->id, $companyId);
        $policy = $this->salaryAdvanceService->policyForCompany($companyId);

        $pending = $advances->filter(fn (SalaryAdvance $a) => in_array($a->status, [
            SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER,
            SalaryAdvance::STATUS_PENDING_HR,
        ], true))->values();

        return [
            'eligibility' => [
                'basic_salary' => (float) $employee->base_salary,
                'policy_configured' => $policy !== null,
                'max_allowed_amount' => $policy
                    ? $this->salaryAdvanceService->maxAllowedAmount($employee, $policy)
                    : 0.0,
                'max_repayment_months' => $policy?->max_repayment_months,
                'allow_multiple_active_advances' => (bool) ($policy?->allow_multiple_active_advances ?? false),
                'has_active_advance' => $active !== null,
            ],
            'pending_requests_count' => $pending->count(),
            'active_advance' => $active ? $this->mapAdvance($active->loadMissing('installments')) : null,
            'latest_request' => $advances->first() ? $this->mapAdvance($advances->first()) : null,
            'recent_requests' => $advances->map(fn (SalaryAdvance $a) => $this->mapAdvance($a))->values()->all(),
        ];
    }

    private function mapAdvance(SalaryAdvance $advance): array
    {
        $installments = $advance->relationLoaded('installments')
            ? $advance->installments
            : $advance->installments()->orderBy('due_date')->get();

        $paidAmount = (float) $installments
            ->where('status', SalaryAdvanceInstallment::STATUS_PAID)
            ->sum('amount');
        $remainingAmount = (float) $installments
            ->where('status', SalaryAdvanceInstallment::STATUS_PENDING)
            ->sum('amount');
        $nextPending = $installments
            ->where('status', SalaryAdvanceInstallment::STATUS_PENDING)
            ->sortBy(fn (SalaryAdvanceInstallment $i) => optional($i->due_date)?->toDateString())
            ->first();

        return [
            'requested_amount' => (float) $advance->requested_amount,
            'repayment_months' => (int) $advance->repayment_months,
            'monthly_installment' => $advance->monthly_installment !== null
                ? (float) $advance->monthly_installment
                : null,
            'status' => $advance->status,
            'rejection_reason' => $advance->rejection_reason,
            'created_at' => optional($advance->created_at)?->toDateTimeString(),
            'paid_amount' => $paidAmount,
            'remaining_amount' => $remainingAmount,
            'next_installment' => $nextPending ? [
                'due_date' => optional($nextPending->due_date)?->toDateString(),
                'amount' => (float) $nextPending->amount,
                'status' => $nextPending->status,
            ] : null,
            'installments' => $installments->map(fn (SalaryAdvanceInstallment $i) => [
                'due_date' => optional($i->due_date)?->toDateString(),
                'amount' => (float) $i->amount,
                'status' => $i->status,
                'paid_at' => optional($i->paid_at)?->toDateTimeString(),
            ])->values()->all(),
        ];
    }
}
