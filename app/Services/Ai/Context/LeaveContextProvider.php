<?php

namespace App\Services\Ai\Context;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Ai\Concerns\MatchesAssistantTopics;
use App\Services\LeaveBalanceService;

/**
 * Read-only leave balances/requests for the authenticated employee.
 * Uses the same LeaveBalanceService calculations as the employee leave dashboard.
 */
class LeaveContextProvider implements EmployeeContextProvider
{
    use MatchesAssistantTopics;

    private const PRIMARY_LEAVE_TYPE_NAME = 'Paid Free Days Leave Allocation';

    public function __construct(
        private readonly LeaveBalanceService $leaveBalanceService,
    ) {}

    public function key(): string
    {
        return 'leaves';
    }

    public function supports(string $message): bool
    {
        return $this->matchesAny($message, [
            'اجازة', 'إجازة', 'اجازات', 'إجازات', 'leave', 'leaves',
            'رصيد الإجازة', 'رصيد الاجازة', 'رصيد إجاز', 'leave balance',
            'طلب إجازة', 'طلب اجازة', 'أنواع الإجاز', 'انواع الاجاز',
            'إجازاتي', 'اجازاتي', 'مرضية', 'سنوية',
        ]);
    }

    public function build(Employee $employee, User $user): array
    {
        $companyId = $employee->company_id;
        $year = now()->year;

        $leaveTypes = LeaveType::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $totalAllowed = 0.0;
        $totalUsed = 0.0;
        $primaryMatched = false;

        $balances = $leaveTypes->map(function (LeaveType $leaveType) use ($employee, $year, &$totalAllowed, &$totalUsed, &$primaryMatched) {
            $balance = $this->leaveBalanceService->syncUsedDays($employee, $leaveType, $year);
            $used = (float) $balance->used_days;
            $remaining = (float) $balance->remaining_days;

            if ($this->isPrimaryLeaveType($leaveType->name)) {
                $totalAllowed = (float) $balance->total_days;
                $totalUsed = $used;
                $primaryMatched = true;
            }

            return [
                'leave_type_name' => $leaveType->name,
                'allocation_value' => (int) $balance->total_days,
                'allocation_unit' => $leaveType->allocation_unit,
                'requires_proof' => (bool) $leaveType->requires_proof,
                'used_value' => (float) $used,
                'remaining_value' => (float) $remaining,
                'is_primary' => $this->isPrimaryLeaveType($leaveType->name),
            ];
        })->values()->all();

        $requests = LeaveRequest::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with('leaveType:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $pending = $requests->filter(fn (LeaveRequest $r) => in_array($r->status, [
            'pending_department_manager',
            'pending_hr',
        ], true))->values();

        $approved = $requests->where('status', 'approved')->values();
        $rejected = $requests->filter(fn (LeaveRequest $r) => str_starts_with($r->status, 'rejected'))->values();

        $upcomingApproved = $approved
            ->filter(fn (LeaveRequest $r) => $r->end_date && ! $r->end_date->isPast())
            ->sortBy('start_date')
            ->values();

        return [
            'year' => $year,
            'primary_summary' => [
                'note' => 'Dashboard totals use Paid Free Days Leave Allocation only (same as employee leave dashboard).',
                'total_allowed_days' => $primaryMatched ? (int) $totalAllowed : 0,
                'total_used_days' => $primaryMatched ? (int) round($totalUsed) : 0,
                'remaining_days' => $primaryMatched ? (int) max(0, round($totalAllowed - $totalUsed)) : 0,
                'primary_type_configured' => $primaryMatched,
            ],
            'balances' => $balances,
            'pending_requests_count' => $pending->count(),
            'pending_requests' => $pending->take(5)->map(fn (LeaveRequest $r) => $this->mapRequest($r))->all(),
            'recent_requests' => $requests->take(10)->map(fn (LeaveRequest $r) => $this->mapRequest($r))->all(),
            'approved_upcoming' => $upcomingApproved->take(5)->map(fn (LeaveRequest $r) => $this->mapRequest($r))->all(),
            'rejected_recent' => $rejected->take(5)->map(fn (LeaveRequest $r) => $this->mapRequest($r))->all(),
            'latest_request' => $requests->first() ? $this->mapRequest($requests->first()) : null,
        ];
    }

    private function mapRequest(LeaveRequest $leaveRequest): array
    {
        return [
            'leave_type_name' => $leaveRequest->leaveType?->name,
            'start_date' => optional($leaveRequest->start_date)?->toDateString(),
            'end_date' => optional($leaveRequest->end_date)?->toDateString(),
            'requested_value' => $leaveRequest->requested_value !== null ? (float) $leaveRequest->requested_value : null,
            'status' => $leaveRequest->status,
            'display_status' => $this->resolveHistoryStatus($leaveRequest),
            'rejection_reason' => $leaveRequest->rejection_reason,
            'reason' => $leaveRequest->reason,
        ];
    }

    private function resolveHistoryStatus(LeaveRequest $leaveRequest): string
    {
        if (str_starts_with($leaveRequest->status, 'rejected')) {
            return 'rejected';
        }

        if ($leaveRequest->status === 'approved' && $leaveRequest->end_date && $leaveRequest->end_date->isPast()) {
            return 'completed';
        }

        if ($leaveRequest->status === 'approved') {
            return 'approved';
        }

        return 'pending';
    }

    private function isPrimaryLeaveType(?string $name): bool
    {
        if (! $name) {
            return false;
        }

        return strcasecmp(trim($name), self::PRIMARY_LEAVE_TYPE_NAME) === 0
            || str_contains(mb_strtolower($name), 'paid free days');
    }
}
