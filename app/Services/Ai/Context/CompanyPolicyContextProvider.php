<?php

namespace App\Services\Ai\Context;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Ai\Concerns\MatchesAssistantTopics;

/**
 * Employee-visible company policies only (mirrors GET /api/employee/company-policies).
 * Does not expose GPS, salary rules, or admin-only policy fields.
 */
class CompanyPolicyContextProvider implements EmployeeContextProvider
{
    use MatchesAssistantTopics;

    public function key(): string
    {
        return 'company_policies';
    }

    public function supports(string $message): bool
    {
        return $this->matchesAny($message, [
            'سياسة', 'سياسات', 'policy', 'policies',
            'دوام رسمي', 'موعد الحضور', 'موعد الانصراف', 'وقت الدوام',
            'حد التأخير', 'حد التاخر', 'الحد المسموح', 'allowed late',
            'work start', 'work end', 'شروط الإجازة', 'شروط الاجازة',
            'تقرير طبي', 'requires_proof', 'minimum daily', 'ساعات العمل',
            'كيف بينخصم', 'احتساب التأخير', 'احتساب التاخر',
        ]);
    }

    public function build(Employee $employee, User $user): array
    {
        $companyId = $employee->company_id;

        $attendancePolicy = AttendancePolicy::where('company_id', $companyId)->first();

        $leavePolicies = LeaveType::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $leaveType) => [
                'name' => $leaveType->name,
                'allocation_value' => $leaveType->allocation_value,
                'allocation_unit' => $leaveType->allocation_unit,
                'requires_proof' => (bool) $leaveType->requires_proof,
            ])
            ->values()
            ->all();

        return [
            'attendance_policy' => $attendancePolicy ? [
                'work_start_time' => $attendancePolicy->work_start_time,
                'work_end_time' => $attendancePolicy->work_end_time,
                'allowed_late_minutes' => $attendancePolicy->allowed_late_minutes,
                'allowed_early_leave_minutes' => $attendancePolicy->allowed_early_leave_minutes,
                'minimum_daily_hours' => $attendancePolicy->minimum_daily_hours,
            ] : null,
            'leave_policies' => $leavePolicies,
            'notes' => [
                'Salary deduction formulas / salary rules are not exposed on the employee policies page.',
                'GPS and perimeter settings are intentionally omitted.',
            ],
        ];
    }
}
