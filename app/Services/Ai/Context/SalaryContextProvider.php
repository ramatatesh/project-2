<?php

namespace App\Services\Ai\Context;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\Employee;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Services\Ai\Concerns\MatchesAssistantTopics;
use App\Services\SalaryService;

/**
 * Read-only salary records for the authenticated employee only.
 */
class SalaryContextProvider implements EmployeeContextProvider
{
    use MatchesAssistantTopics;

    public function __construct(
        private readonly SalaryService $salaryService,
    ) {}

    public function key(): string
    {
        return 'salary';
    }

    public function supports(string $message): bool
    {
        return $this->matchesAny($message, [
            'راتب', 'رواتب', 'salary', 'salaries', 'صافي', 'net',
            'خصم', 'خصومات', 'deduction', 'بونص', 'bonus', 'overtime', 'أوفر تايم', 'اوفر تايم',
            'أساسي', 'اساسي', 'base salary', 'payslip', 'كشف راتب',
        ]);
    }

    public function build(Employee $employee, User $user): array
    {
        $companyId = $employee->company_id;

        $records = SalaryRecord::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with('salaryAdjustments')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(6)
            ->get();

        $lastReceived = $this->salaryService->lastReceived($employee);
        $latest = $records->first();

        $previousMonth = now()->subMonthNoOverflow();
        $previousMonthRecord = SalaryRecord::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('month', (int) $previousMonth->month)
            ->where('year', (int) $previousMonth->year)
            ->with('salaryAdjustments')
            ->first();

        return [
            'configured_base_salary' => $employee->base_salary !== null ? (float) $employee->base_salary : null,
            'last_received_salary' => $lastReceived
                ? [
                    'amount' => (float) $lastReceived->net_salary,
                    'month' => (int) $lastReceived->month,
                    'year' => (int) $lastReceived->year,
                    'period' => sprintf('%04d-%02d', $lastReceived->year, $lastReceived->month),
                    'received_at' => optional($lastReceived->closed_at)?->toDateTimeString(),
                    'payment_summary' => $this->salaryService->paymentSummary($lastReceived),
                ]
                : null,
            'latest_record' => $latest
                ? $this->salaryService->serializeDetails($latest)
                : null,
            'previous_month_record' => $previousMonthRecord
                ? $this->salaryService->serializeDetails($previousMonthRecord)
                : null,
            'recent_summaries' => $records
                ->map(fn (SalaryRecord $record) => $this->salaryService->serializeSummary($record))
                ->values()
                ->all(),
            'notes' => [
                'Only this employee\'s salary records are included.',
                'Future / uncalculated salaries are not invented.',
            ],
        ];
    }
}
