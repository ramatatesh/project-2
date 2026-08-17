<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeLeaveRequest;
use App\Http\Requests\LeaveAttachmentUploadRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveAttachmentService;
use App\Services\LeaveBalanceService;
use App\Services\LeaveDurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @OA\Tag(
 *    name="Employee Leaves",
 *    description="Employee leave management endpoints"
 * )
 */
class EmployeeLeaveController extends Controller
{
    public function __construct(
        private readonly LeaveDurationService $leaveDurationService,
        private readonly LeaveBalanceService $leaveBalanceService,
        private readonly LeaveAttachmentService $leaveAttachmentService,
    ) {
    }

    private const PRIMARY_LEAVE_TYPE_NAME = 'Paid Free Days Leave Allocation';

    /**
     * @OA\Get(
     *    path="/api/employee/leaves/dashboard",
     *    summary="Leave balance and history for the current employee",
     *    tags={"Employee Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\Response(
     *      response=200,
     *      description="Current year leave dashboard",
     *      @OA\JsonContent(
     *        @OA\Property(property="success", type="boolean", example=true),
     *        @OA\Property(property="data", type="object",
     *          @OA\Property(property="total_allowed_days", type="integer"),
     *          @OA\Property(property="total_used_days", type="integer"),
     *          @OA\Property(property="remaining_days", type="integer"),
     *          @OA\Property(property="balances", type="array", @OA\Items(type="object")),
     *          @OA\Property(property="leave_history", type="array",
     *            @OA\Items(
     *              @OA\Property(property="id", type="string", format="uuid"),
     *              @OA\Property(property="leave_type_name", type="string"),
     *              @OA\Property(property="start_date", type="string", format="date"),
     *              @OA\Property(property="duration_days", type="integer"),
     *              @OA\Property(property="status", type="string"),
     *              @OA\Property(property="attachment_url", type="string", nullable=true)
     *            )
     *          )
     *        )
     *      )
     *    )
     * )
     */
    public function dashboard(): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;
        $companyId = $user?->company_id;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $year = now()->year;

        $leaveTypes = LeaveType::where('company_id', $companyId)
            ->where('is_active', true)
            ->get(['id', 'name', 'allocation_value']);

        $totalAllowed = 0;
        $totalUsed = 0;
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
                'id' => $leaveType->id,
                'name' => $leaveType->name,
                'allocation_value' => (int) $balance->total_days,
                'used_value' => (int) round($used),
                'remaining_value' => (int) round($remaining),
                'is_primary' => $this->isPrimaryLeaveType($leaveType->name),
            ];
        });

        $leaveHistory = LeaveRequest::where('employee_id', $employee->id)
            ->whereYear('start_date', $year)
            ->with('leaveType:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (LeaveRequest $leaveRequest) {
                return [
                    'id' => $leaveRequest->id,
                    'leave_type_name' => $leaveRequest->leaveType?->name,
                    'start_date' => $leaveRequest->start_date?->toDateString(),
                    'duration_days' => (int) $leaveRequest->requested_value,
                    'status' => $this->resolveHistoryStatus($leaveRequest),
                    'attachment_url' => $this->leaveAttachmentService->publicUrl($leaveRequest->attachment_url),
                    'has_attachment' => $this->leaveAttachmentService->exists($leaveRequest->attachment_url),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'total_allowed_days' => $primaryMatched ? (int) $totalAllowed : 0,
                'total_used_days' => $primaryMatched ? (int) round($totalUsed) : 0,
                'remaining_days' => $primaryMatched ? (int) max(0, round($totalAllowed - $totalUsed)) : 0,
                'balances' => $balances,
                'leave_history' => $leaveHistory,
            ],
        ]);
    }
/**
     * @OA\Get(
     *    path="/api/employee/leaves/types",
     *    summary="Fetch dynamic leave types for the current employee's company",
     *    tags={"Employee Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\Response(
     *      response=200,
     *      description="Active leave types for the company",
     *      @OA\JsonContent(
     *        @OA\Property(property="success", type="boolean", example=true),
     *        @OA\Property(property="data", type="array",
     *          @OA\Items(
     *            @OA\Property(property="id", type="string", format="uuid"),
     *            @OA\Property(property="name", type="string"),
     *            @OA\Property(property="allocation_value", type="integer"),
     *            @OA\Property(property="allocation_unit", type="string"),
     *            @OA\Property(property="requires_proof", type="boolean")
     *          )
     *        )
     *      )
     *    ),
     *    @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function types(): JsonResponse
    {
        $companyId = auth()->user()?->company_id;

        $leaveTypes = LeaveType::where('company_id', $companyId)
            ->where('is_active', true)
            ->get(['id', 'name', 'allocation_value', 'allocation_unit', 'requires_proof']);

        return response()->json([
            'success' => true,
            'data' => $leaveTypes,
        ]);
    }
/**
     * @OA\Post(
     *    path="/api/employee/leaves/upload-attachment",
     *    summary="Upload leave proof file (multipart), same pattern as Excel employee import",
     *    description="Send multipart/form-data with field name `file` (pdf/jpg/jpeg/png, max 5MB). Returns path to use in apply as attachment_path, or you can send `file` directly on /apply.",
     *    tags={"Employee Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\RequestBody(
     *      required=true,
     *      @OA\MediaType(
     *        mediaType="multipart/form-data",
     *        @OA\Schema(
     *          required={"file"},
     *          @OA\Property(property="file", type="string", format="binary", description="Proof document (pdf/jpg/jpeg/png)")
     *        )
     *      )
     *    ),
     *    @OA\Response(response=201, description="File uploaded"),
     *    @OA\Response(response=422, description="Validation failed")
     * )
     */
    public function uploadAttachment(LeaveAttachmentUploadRequest $request): JsonResponse
    {
        $path = $this->leaveAttachmentService->store($request->file('file'));

        return response()->json([
            'success' => true,
            'message' => 'Attachment uploaded successfully.',
            'data' => [
                'path' => $path,
                'url' => $this->leaveAttachmentService->publicUrl($path),
                'original_name' => $request->file('file')->getClientOriginalName(),
            ],
        ], 201);
    }

/**
     * @OA\Post(
     *    path="/api/employee/leaves/apply",
     *    summary="Submit a leave request",
     *    description="Use multipart/form-data. Proof file: send field `file` (same as Excel import) or `attachment`, or first call upload-attachment and pass `attachment_path`.",
     *    tags={"Employee Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\RequestBody(
     *      required=true,
     *      @OA\MediaType(
     *        mediaType="multipart/form-data",
     *        @OA\Schema(
     *          required={"leave_type_id","duration_type","start_date"},
     *          @OA\Property(property="leave_type_id", type="string", format="uuid"),
     *          @OA\Property(property="duration_type", type="string", enum={"single_day","multiple_days"}),
     *          @OA\Property(property="start_date", type="string", format="date"),
     *          @OA\Property(property="end_date", type="string", format="date", nullable=true),
     *          @OA\Property(property="start_time", type="string", format="time", nullable=true),
     *          @OA\Property(property="end_time", type="string", format="time", nullable=true),
     *          @OA\Property(property="reason", type="string", nullable=true),
     *          @OA\Property(property="file", type="string", format="binary", nullable=true, description="Proof file — same field name as Excel import"),
     *          @OA\Property(property="attachment", type="string", format="binary", nullable=true, description="Alias of file"),
     *          @OA\Property(property="attachment_path", type="string", nullable=true, description="Path returned by upload-attachment")
     *        )
     *      )
     *    ),
     *    @OA\Response(response=201, description="Leave request submitted successfully"),
     *    @OA\Response(response=422, description="Validation failed or insufficient balance")
     * )
     */
    public function apply(EmployeeLeaveRequest $request): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;
        $companyId = $user?->company_id;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $data = $request->validated();

        $leaveType = LeaveType::where('id', $data['leave_type_id'])
            ->where('company_id', $companyId)
            ->firstOrFail();

        $startDate = $data['start_date'];
        $endDate = $data['duration_type'] === 'single_day'
            ? $startDate
            : $data['end_date'];

        if ($this->leaveBalanceService->hasOverlappingRequest($employee->id, $startDate, $endDate)) {
            return response()->json([
                'success' => false,
                'message' => 'Requested dates overlap with an existing leave request.',
            ], 422);
        }

        $durationDays = $this->leaveDurationService->calculateWorkingDays($companyId, $startDate, $endDate);

        if ($durationDays <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Requested period contains no working days.',
            ], 422);
        }

        $year = (int) date('Y', strtotime($startDate));
        $balance = $this->leaveBalanceService->syncUsedDays($employee, $leaveType, $year);
        $remaining = (float) $balance->remaining_days;

        if ($durationDays > $remaining) {
            return response()->json([
                'success' => false,
                'message' => 'Requested duration exceeds remaining leave balance.',
                'remaining_balance' => max(0, $remaining),
            ], 422);
        }

        $attachmentPath = null;
        $uploaded = $request->file('file') ?? $request->file('attachment');
        if ($uploaded) {
            $attachmentPath = $this->leaveAttachmentService->store($uploaded);
        } elseif (! empty($data['attachment_path'])) {
            $attachmentPath = $this->leaveAttachmentService->normalizeStoredValue($data['attachment_path']);
        }

        $employee->loadMissing('department');
        $isOwnDepartmentManager = (bool) ($employee->department && $employee->department->manager_id === $employee->id);

        $leaveRequest = LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'requested_value' => $durationDays,
            'attachment_url' => $attachmentPath,
            'reason' => $data['reason'] ?? null,
            'status' => $isOwnDepartmentManager ? 'pending_hr' : 'pending_department_manager',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully.',
            'data' => [
                ...$leaveRequest->toArray(),
                'attachment_url' => $this->leaveAttachmentService->publicUrl($leaveRequest->attachment_url),
                'has_attachment' => $this->leaveAttachmentService->exists($leaveRequest->attachment_url),
            ],
        ], 201);
    }

    /**
     * @OA\Get(
     *    path="/api/employee/leaves/{id}/attachment",
     *    summary="Download leave proof file (authenticated, like Excel template download)",
     *    tags={"Employee Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *    @OA\Response(response=200, description="File download"),
     *    @OA\Response(response=404, description="Not found")
     * )
     */
    public function downloadAttachment(string $id): StreamedResponse|JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $leaveRequest = LeaveRequest::where('id', $id)
            ->where('employee_id', $employee->id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        if (! $this->leaveAttachmentService->exists($leaveRequest->attachment_url)) {
            return response()->json([
                'success' => false,
                'message' => 'Attachment not found.',
            ], 404);
        }

        return $this->leaveAttachmentService->download($leaveRequest);
    }

    /**
     * @OA\Post(
     *    path="/api/employee/leaves/{id}/cancel",
     *    summary="Cancel a leave request",
     *    tags={"Employee Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\Parameter(
     *      name="id",
     *      in="path",
     *      required=true,
     *      @OA\Schema(type="string", format="uuid")
     *    ),
     *    @OA\Response(response=200, description="Leave request cancelled successfully"),
     *    @OA\Response(response=422, description="Cannot cancel completed or past leaves")
     * )
     */
    public function cancel(string $id): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        return DB::transaction(function () use ($id, $employee) {
            $leaveRequest = LeaveRequest::where('id', $id)
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            // لا يمكن إلغاء الطلبات الملغاة أو المرفوضة سابقاً
            if (in_array($leaveRequest->status, ['cancelled', 'rejected_by_manager', 'rejected_by_hr'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Leave request is already in a non-cancellable state.',
                ], 422);
            }

            // إذا كانت الإجازة مقبولة وانتهت بالفعل، لا يمكن إلغاؤها
            if ($leaveRequest->status === 'approved' && $leaveRequest->end_date && $leaveRequest->end_date->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel a completed past leave.',
                ], 422);
            }

            $wasApproved = ($leaveRequest->status === 'approved');

            $leaveRequest->status = 'cancelled';
            $leaveRequest->save();

            // إذا كانت الإجازة مقبولة مسبقاً، يجب إعادتها لرصيد الموظف ومزامنة الأيام المستهلكة
            if ($wasApproved && $leaveRequest->leaveType) {
                $year = (int) $leaveRequest->start_date->year;
                $this->leaveBalanceService->syncUsedDays($employee, $leaveRequest->leaveType, $year);
            }

            return response()->json([
                'success' => true,
                'message' => 'Leave request cancelled successfully.',
                'data' => [
                    'id' => $leaveRequest->id,
                    'status' => $leaveRequest->status,
                ],
            ]);
        });
    }

    private function resolveHistoryStatus(LeaveRequest $leaveRequest): string
    {
        if (str_starts_with($leaveRequest->status, 'rejected')) {
            return 'rejected';
        }

        if ($leaveRequest->status === 'cancelled') {
            return 'cancelled';
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
