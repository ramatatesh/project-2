<?php

namespace App\Services\Ai\Context;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Ai\Concerns\MatchesAssistantTopics;
use App\Services\LeaveDurationService;
use Carbon\Carbon;

/**
 * Read-only attendance summary for the authenticated employee (same fields as employee attendance APIs).
 */
class AttendanceContextProvider implements EmployeeContextProvider
{
    use MatchesAssistantTopics;

    public function __construct(
        private readonly LeaveDurationService $leaveDurationService,
    ) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function supports(string $message): bool
    {
        return $this->matchesAny($message, [
            'حضور', 'غياب', 'غبت', 'تأخر', 'تأخير', 'دوام', 'دخول', 'خروج',
            'attendance', 'check-in', 'check in', 'check-out', 'checkout', 'absent', 'late',
            'سجلت', 'حضرت', 'انصراف', 'بصمة',
        ]);
    }

    public function build(Employee $employee, User $user): array
    {
        $companyId = $employee->company_id;
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        $todayRecord = AttendanceRecord::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $today->toDateString())
            ->first();

        $monthRecords = AttendanceRecord::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('work_date')
            ->get();

        $presentDays = $monthRecords
            ->whereIn('attendance_type', [
                AttendanceRecord::TYPE_PRESENT,
                AttendanceRecord::TYPE_LATE,
                AttendanceRecord::TYPE_EARLY_LEAVE,
            ])
            ->count();

        $absentDays = $monthRecords
            ->where('attendance_type', AttendanceRecord::TYPE_ABSENT)
            ->count();

        $lateCount = $monthRecords
            ->filter(fn (AttendanceRecord $r) => (int) $r->late_minutes > 0)
            ->count();

        $approvedLeaves = LeaveRequest::where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->whereDate('end_date', '>=', $monthStart->toDateString())
            ->get(['start_date', 'end_date']);

        $leaveDays = 0;
        foreach ($approvedLeaves as $leave) {
            $rangeStart = Carbon::parse($leave->start_date)->max($monthStart);
            $rangeEnd = Carbon::parse($leave->end_date)->min($monthEnd);
            $leaveDays += $this->leaveDurationService->calculateWorkingDays(
                $companyId,
                $rangeStart,
                $rangeEnd,
            );
        }

        $policy = AttendancePolicy::where('company_id', $companyId)->first();

        return [
            'today' => [
                'date' => $today->toDateString(),
                'has_record' => $todayRecord !== null,
                'record' => $todayRecord ? $this->mapRecord($todayRecord) : null,
            ],
            'current_month' => [
                'month' => $today->format('Y-m'),
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'leave_days' => $leaveDays,
                'late_occurrences' => $lateCount,
                'total_late_minutes' => (int) $monthRecords->sum('late_minutes'),
                'total_work_hours' => round($monthRecords->sum('total_work_minutes') / 60, 2),
                'recent_records' => $monthRecords
                    ->sortByDesc(fn (AttendanceRecord $r) => optional($r->work_date)?->toDateString())
                    ->take(10)
                    ->values()
                    ->map(fn (AttendanceRecord $r) => $this->mapRecord($r))
                    ->all(),
            ],
            // Same employee-visible attendance policy fields as GET /api/employee/company-policies
            'attendance_policy' => $policy ? [
                'work_start_time' => $policy->work_start_time,
                'work_end_time' => $policy->work_end_time,
                'allowed_late_minutes' => $policy->allowed_late_minutes,
                'allowed_early_leave_minutes' => $policy->allowed_early_leave_minutes,
                'minimum_daily_hours' => $policy->minimum_daily_hours,
            ] : null,
            'notes' => [
                'Absence justification beyond attendance_type/status is not available in this context.',
                'GPS coordinates, devices, and QR tokens are never included.',
            ],
        ];
    }

    private function mapRecord(AttendanceRecord $record): array
    {
        return [
            'work_date' => optional($record->work_date)?->toDateString(),
            'check_in_time' => optional($record->check_in_time)?->toDateTimeString(),
            'check_out_time' => optional($record->check_out_time)?->toDateTimeString(),
            'late_minutes' => $record->late_minutes,
            'early_leave_minutes' => $record->early_leave_minutes,
            'total_work_minutes' => $record->total_work_minutes,
            'status' => $record->status,
            'attendance_type' => $record->attendance_type,
        ];
    }
}
