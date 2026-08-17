<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceCheckInRequest;
use App\Http\Requests\AttendanceCheckOutRequest;
use App\Models\LeaveRequest;
use App\Models\AttendanceRecord;
use App\Services\AttendanceService;
use App\Services\LeaveDurationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *   name="Employee Attendance",
 *   description="Employee self-service check-in/check-out and personal attendance dashboard"
 * )
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly LeaveDurationService $leaveDurationService,
    ) {
    }

    /**
     * @OA\Post(
     *   path="/api/employee/attendance/check-in",
     *   summary="Check in using the current QR code",
     *   tags={"Employee Attendance"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"qr_token","device_id"},
     *       @OA\Property(property="qr_token", type="string", example="29234567.a1b2c3d4e5f6a7b8c9d0e1f2"),
     *       @OA\Property(property="latitude", type="number", format="float", nullable=true, example=33.5138),
     *       @OA\Property(property="longitude", type="number", format="float", nullable=true, example=36.2765),
     *       @OA\Property(property="device_id", type="string", example="device-abc-123", description="Stable mobile device id; first check-in binds it to the employee")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Checked in successfully"),
     *   @OA\Response(response=422, description="Invalid/expired QR, GPS out of range, device mismatch, or already checked in"),
     *   @OA\Response(response=403, description="No employee record found")
     * )
     */
    public function checkIn(AttendanceCheckInRequest $request): JsonResponse
    {
        $employee = auth()->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $result = $this->attendanceService->checkIn($employee, $request->validated());

        if (! $result['success']) {
            return response()->json($result, 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Checked in successfully.',
            'device_bound_now' => (bool) ($result['device_bound_now'] ?? false),
            'data' => $this->mapRecord($result['record']),
        ], 201);
    }

    /**
     * @OA\Post(
     *   path="/api/employee/attendance/check-out",
     *   summary="Check out using the current QR code",
     *   tags={"Employee Attendance"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"qr_token","device_id"},
     *       @OA\Property(property="qr_token", type="string", example="29234568.b2c3d4e5f6a7b8c9d0e1f2a3"),
     *       @OA\Property(property="latitude", type="number", format="float", nullable=true, example=33.5138),
     *       @OA\Property(property="longitude", type="number", format="float", nullable=true, example=36.2765),
     *       @OA\Property(property="device_id", type="string", example="device-abc-123")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Checked out successfully"),
     *   @OA\Response(response=422, description="Invalid/expired QR, GPS out of range, device mismatch, not checked in, or already checked out"),
     *   @OA\Response(response=403, description="No employee record found")
     * )
     */
    public function checkOut(AttendanceCheckOutRequest $request): JsonResponse
    {
        $employee = auth()->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $result = $this->attendanceService->checkOut($employee, $request->validated());

        if (! $result['success']) {
            return response()->json($result, 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Checked out successfully.',
            'data' => $this->mapRecord($result['record']),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/employee/attendance/dashboard",
     *   summary="The logged-in employee's monthly attendance summary and record list",
     *   tags={"Employee Attendance"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="month",
     *     in="query",
     *     required=false,
     *     description="Month to summarize, format YYYY-MM. Defaults to the current month.",
     *     @OA\Schema(type="string", example="2026-07")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Monthly attendance summary",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="month", type="string", example="2026-07"),
     *         @OA\Property(property="present_days", type="integer"),
     *         @OA\Property(property="absent_days", type="integer"),
     *         @OA\Property(property="total_late_minutes", type="integer"),
     *         @OA\Property(property="total_work_hours", type="number", format="float"),
     *         @OA\Property(property="records", type="array", @OA\Items(type="object"))
     *       )
     *     )
     *   ),
     *   @OA\Response(response=403, description="No employee record found"),
     *   @OA\Response(response=422, description="Invalid month format")
     * )
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $monthInput = $request->input('month');

        try {
            $monthDate = $monthInput ? Carbon::createFromFormat('Y-m', $monthInput) : Carbon::now();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid month format. Use YYYY-MM.',
            ], 422);
        }

        $start = $monthDate->copy()->startOfMonth();
        $end = $monthDate->copy()->endOfMonth();

        $records = AttendanceRecord::where('company_id', $user->company_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('work_date')
            ->get();

        $presentDays = $records
           ->whereIn('attendance_type', [
               AttendanceRecord::TYPE_PRESENT,
               AttendanceRecord::TYPE_LATE,
               AttendanceRecord::TYPE_EARLY_LEAVE,
            ])
           ->count();

        $absentDays = $records
           ->where('attendance_type', AttendanceRecord::TYPE_ABSENT)
           ->count();

        $approvedLeaves = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get(['start_date', 'end_date']);

        $leaveDays = 0;
        foreach ($approvedLeaves as $leave) {
            $rangeStart = Carbon::parse($leave->start_date)->max($start);
            $rangeEnd = Carbon::parse($leave->end_date)->min($end);
            $leaveDays += $this->leaveDurationService->calculateWorkingDays(
                $user->company_id,
                $rangeStart,
                $rangeEnd,
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $monthDate->format('Y-m'),
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'leave_days' => $leaveDays,
                'total_late_minutes' => (int) $records->sum('late_minutes'),
                'total_work_hours' => round($records->sum('total_work_minutes') / 60, 2),
                'records' => $records->map(fn (AttendanceRecord $record) => $this->mapRecord($record))->values(),
            ],
        ]);
    }

    private function mapRecord(AttendanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'work_date' => $record->work_date?->toDateString(),
            'check_in_time' => $record->check_in_time?->toDateTimeString(),
            'check_out_time' => $record->check_out_time?->toDateTimeString(),
            'late_minutes' => $record->late_minutes,
            'early_leave_minutes' => $record->early_leave_minutes,
            'total_work_minutes' => $record->total_work_minutes,
            'status' => $record->status,
            'attendance_type' => $record->attendance_type,
        ];
    }
}
