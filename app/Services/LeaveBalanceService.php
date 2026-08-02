<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Str;

class LeaveBalanceService
{
    public const ACTIVE_OVERLAP_STATUSES = [
        'pending_department_manager',
        'pending_hr',
        'approved',
    ];

    /**
     * Ensure a yearly leave balance row exists for the employee + leave type.
     * Creates a fresh allocation for the year when missing (lazy renewal).
     */
    public function ensureBalance(Employee $employee, LeaveType $leaveType, ?int $year = null): LeaveBalance
    {
        $year ??= now()->year;

        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->first();

        if ($balance) {
            return $balance;
        }

        $used = $this->sumApprovedUsed($employee->id, $leaveType->id, $year);
        $total = (float) $leaveType->allocation_value;

        return LeaveBalance::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $year,
            'total_days' => $total,
            'used_days' => $used,
            'remaining_days' => max(0, $total - $used),
        ]);
    }

    /**
     * Create missing yearly balances for all active employees × active leave types.
     *
     * @return array{created: int, skipped: int}
     */
    public function renewForYear(int $year): array
    {
        $created = 0;
        $skipped = 0;

        Employee::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($employees) use ($year, &$created, &$skipped) {
                foreach ($employees as $employee) {
                    $leaveTypes = LeaveType::where('company_id', $employee->company_id)
                        ->where('is_active', true)
                        ->get();

                    foreach ($leaveTypes as $leaveType) {
                        $exists = LeaveBalance::where('employee_id', $employee->id)
                            ->where('leave_type_id', $leaveType->id)
                            ->where('year', $year)
                            ->exists();

                        if ($exists) {
                            $skipped++;
                            continue;
                        }

                        $this->ensureBalance($employee, $leaveType, $year);
                        $created++;
                    }
                }
            });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Initialize current-year balances for a newly hired employee.
     */
    public function initializeForEmployee(Employee $employee, ?int $year = null): void
    {
        $year ??= now()->year;

        $leaveTypes = LeaveType::where('company_id', $employee->company_id)
            ->where('is_active', true)
            ->get();

        foreach ($leaveTypes as $leaveType) {
            $this->ensureBalance($employee, $leaveType, $year);
        }
    }

    /**
     * Recalculate used/remaining from approved leave requests for that year.
     */
    public function syncUsedDays(Employee $employee, LeaveType $leaveType, int $year): LeaveBalance
    {
        $balance = $this->ensureBalance($employee, $leaveType, $year);
        $used = $this->sumApprovedUsed($employee->id, $leaveType->id, $year);
        $total = (float) $balance->total_days;

        $balance->used_days = $used;
        $balance->remaining_days = max(0, $total - $used);
        $balance->save();

        return $balance;
    }

    public function remainingDays(Employee $employee, LeaveType $leaveType, ?int $year = null): float
    {
        return (float) $this->ensureBalance($employee, $leaveType, $year)->remaining_days;
    }

    public function hasOverlappingRequest(
        string $employeeId,
        string $startDate,
        string $endDate,
        ?string $excludeRequestId = null,
    ): bool {
        $query = LeaveRequest::where('employee_id', $employeeId)
            ->whereIn('status', self::ACTIVE_OVERLAP_STATUSES)
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);

        if ($excludeRequestId) {
            $query->where('id', '!=', $excludeRequestId);
        }

        return $query->exists();
    }

    private function sumApprovedUsed(string $employeeId, string $leaveTypeId, int $year): float
    {
        return (float) LeaveRequest::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', 'approved')
            ->whereYear('start_date', $year)
            ->sum('requested_value');
    }
}
