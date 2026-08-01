<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AttendanceAdjustmentRequest;
use App\Models\AttendanceRecord;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *   name="Management Attendance",
 *   description="Attendance dashboard, filtering, QR display and manual adjustments for HR / General Manager / Department Manager"
 * )
 */
class ManagementAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    /**
     * @OA\Get(
     *   path="/api/management/attendance",
     *   summary="List attendance records (filter by date, department, employee)",
     *   description="HR Manager and General Manager see the whole company. Department Manager only sees employees in departments they manage.",
     *   tags={"Management Attendance"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="employee_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *   @OA\Response(response=200, description="Paginated attendance records")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedQuery($request)->with(['employee.user', 'employee.department']);

        $this->applyFilters($query, $request);

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $paginator = $query->orderByDesc('work_date')->paginate($perPage);

        $paginator->getCollection()->transform(fn (AttendanceRecord $record) => $this->mapRecord($record));

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/management/attendance/stats",
     *   summary="Attendance statistics for a date or date range",
     *   description="Defaults to today if no date filter is given. Department Manager statistics are scoped to their own department(s).",
     *   tags={"Management Attendance"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(
     *     response=200,
     *     description="Attendance statistics",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="present", type="integer"),
     *         @OA\Property(property="late", type="integer"),
     *         @OA\Property(property="early_leave", type="integer"),
     *         @OA\Property(property="absent", type="integer"),
     *         @OA\Property(property="off_day", type="integer"),
     *         @OA\Property(property="total_records", type="integer")
     *       )
     *     )
     *   )
     * )
     */
    public function stats(Request $request): JsonResponse
    {
        $query = $this->scopedQuery($request);
        $this->applyFilters($query, $request, defaultToday: true);

        $records = $query->get(['id', 'attendance_type', 'status']);

        return response()->json([
            'success' => true,
            'data' => [
                'present' => $records->where('attendance_type', AttendanceRecord::TYPE_PRESENT)->count(),

                'late' => $records->where('attendance_type', AttendanceRecord::TYPE_LATE)->count(),

                'early_leave' => $records->where('attendance_type', AttendanceRecord::TYPE_EARLY_LEAVE)->count(),

                'absent' => $records->where('attendance_type', AttendanceRecord::TYPE_ABSENT)->count(),

                'total_records' => $records->count(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/management/attendance/qr-code",
     *   summary="Current rotating QR code for this company, image included (display on a kiosk/screen)",
     *   description="The token - and therefore the QR image - changes every 60 seconds. The image is rendered entirely on the backend (PNG, base64 data URI), so the web app, Flutter, and any future kiosk screen all just display the exact same 'qr_image' rather than generating their own QR code from the raw token.",
     *   tags={"Management Attendance"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Current token and QR image",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="token", type="string", example="29234567.a1b2c3d4e5f6a7b8c9d0e1f2"),
     *         @OA\Property(property="qr_image", type="string", description="data:image/png;base64,... - ready to use directly as an <img> src on web, or base64-decoded on Flutter", example="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."),
     *         @OA\Property(property="expires_in_seconds", type="integer", example=42)
     *       )
     *     )
     *   )
     * )
     */
    public function qrCode(): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        return response()->json([
            'success' => true,
            'data' => $this->attendanceService->currentQrToken($companyId),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/management/attendance/{attendanceRecord}/adjust",
     *   summary="Manually adjust an attendance record's check-in/check-out time (HR / General Manager only)",
     *   tags={"Management Attendance"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="attendanceRecord", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"reason"},
     *       @OA\Property(property="new_check_in", type="string", format="date-time", nullable=true),
     *       @OA\Property(property="new_check_out", type="string", format="date-time", nullable=true),
     *       @OA\Property(property="reason", type="string", example="Forgot to check out, confirmed with manager")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Record adjusted and minutes recalculated"),
     *   @OA\Response(response=404, description="Not found / not in your company"),
     *   @OA\Response(response=422, description="Validation failed")
     * )
     */
    public function adjust(AttendanceAdjustmentRequest $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        $user = auth()->user();

        if ($attendanceRecord->company_id !== $user->company_id) {
            abort(404, 'Attendance record not found.');
        }

        $record = $this->attendanceService->adjust($attendanceRecord, $user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Attendance record adjusted successfully.',
            'data' => $this->mapRecord($record),
        ]);
    }

    private function scopedQuery(Request $request)
    {
        $user = auth()->user();
        $employee = $user?->employee;

        $query = AttendanceRecord::where('company_id', $user?->company_id);

        if ($user?->role === Role::DepartmentManager->value) {
            $managedDepartmentIds = DB::table('departments')->where('manager_id', $employee?->id)->pluck('id');

            $query->whereHas('employee', function ($q) use ($managedDepartmentIds) {
                $q->whereIn('department_id', $managedDepartmentIds);
            });
        }

        return $query;
    }

    private function applyFilters($query, Request $request, bool $defaultToday = false): void
    {
        $date = $request->input('date');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if ($date) {
            $query->where('work_date', $date);
        } elseif ($dateFrom || $dateTo) {
            if ($dateFrom) {
                $query->where('work_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('work_date', '<=', $dateTo);
            }
        } elseif ($defaultToday) {
            $query->where('work_date', now()->toDateString());
        }

        if ($departmentId = $request->input('department_id')) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $departmentId));
        }

        if ($employeeId = $request->input('employee_id')) {
            $query->where('employee_id', $employeeId);
        }
    }

    private function mapRecord(AttendanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'employee_id' => $record->employee_id,
            'employee_name' => $record->employee?->user?->full_name,
            'department_name' => $record->employee?->department?->name,
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
