<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ManagementLeaveActionRequest;
use App\Models\LeaveRequest;
use App\Services\LeaveAttachmentService;
use App\Services\LeaveBalanceService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;


/**
 * @OA\Tag(
 *    name="Management Leaves",
 *    description="Leave request approval workflow for department managers and HR"
 * )
 */
class ManagementLeaveController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $leaveBalanceService,
        private readonly LeaveAttachmentService $leaveAttachmentService,
    ) {
    }

    /**
     * @OA\Get(
     *    path="/api/management/leaves/inbox",
     *    summary="Inbox of leave requests awaiting the current reviewer's decision",
     *    tags={"Management Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\Response(
     *      response=200,
     *      description="Pending leave requests for the authenticated manager or HR",
     *      @OA\JsonContent(
     *        @OA\Property(property="success", type="boolean", example=true),
     *        @OA\Property(property="data", type="array",
     *          @OA\Items(
     *            @OA\Property(property="id", type="string", format="uuid"),
     *            @OA\Property(property="employee_name", type="string"),
     *            @OA\Property(property="department_name", type="string", nullable=true),
     *            @OA\Property(property="leave_type_name", type="string"),
     *            @OA\Property(property="start_date", type="string", format="date"),
     *            @OA\Property(property="end_date", type="string", format="date"),
     *            @OA\Property(property="duration_days", type="integer"),
     *            @OA\Property(property="reason", type="string", nullable=true),
     *            @OA\Property(property="attachment_url", type="string", nullable=true),
     *            @OA\Property(property="status", type="string"),
     *            @OA\Property(property="remaining_balance_days", type="integer")
     *          )
     *        )
     *      )
     *    )
     * )
     */
    public function inbox(): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;
        $employee = $user?->employee;
        $role = $user?->role;

        $query = LeaveRequest::where('company_id', $companyId)
            ->with(['employee.user', 'leaveType', 'employee.department']);

        if ($role === Role::DepartmentManager->value) {
            $managedDepartmentIds = DB::table('departments')
                ->where('manager_id', $employee?->id)
                ->pluck('id');

            $query->where('status', 'pending_department_manager')
                ->whereHas('employee', function ($q) use ($managedDepartmentIds) {
                    $q->whereIn('department_id', $managedDepartmentIds);
                });
        } elseif ($role === Role::HrManager->value) {
            $query->where('status', 'pending_hr');
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized role for leave inbox.',
            ], 403);
        }

        $requests = $query->orderByDesc('created_at')
            ->get()
            ->map(function (LeaveRequest $leaveRequest) {
                return [
                    'id' => $leaveRequest->id,
                    'employee_name' => $leaveRequest->employee?->user?->full_name,
                    'department_name' => $leaveRequest->employee?->department?->name,
                    'leave_type_name' => $leaveRequest->leaveType?->name,
                    'start_date' => $leaveRequest->start_date?->toDateString(),
                    'end_date' => $leaveRequest->end_date?->toDateString(),
                    'duration_days' => (int) $leaveRequest->requested_value,
                    'reason' => $leaveRequest->reason,
                    'attachment_url' => $this->leaveAttachmentService->publicUrl($leaveRequest->attachment_url),
                    'has_attachment' => $this->leaveAttachmentService->exists($leaveRequest->attachment_url),
                    'status' => $leaveRequest->status,
                    'remaining_balance_days' => $this->calculateRemainingBalance($leaveRequest),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * @OA\Get(
     *    path="/api/management/leaves/{id}/attachment",
     *    summary="Download leave proof file for a request in your company",
     *    tags={"Management Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *    @OA\Response(response=200, description="File download"),
     *    @OA\Response(response=404, description="Not found")
     * )
     */
    public function downloadAttachment(string $id): StreamedResponse|JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;
        $employee = $user?->employee;
        $role = $user?->role;

        $query = LeaveRequest::where('id', $id)->where('company_id', $companyId);

        if ($role === Role::DepartmentManager->value) {
            $managedDepartmentIds = DB::table('departments')
                ->where('manager_id', $employee?->id)
                ->pluck('id');

            $query->whereHas('employee', function ($q) use ($managedDepartmentIds) {
                $q->whereIn('department_id', $managedDepartmentIds);
            });
        } elseif ($role !== Role::HrManager->value) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized role.',
            ], 403);
        }

        $leaveRequest = $query->firstOrFail();

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
     *    path="/api/management/leaves/{id}/action",
     *    summary="Approve or reject a leave request (department manager step, then HR step)",
     *    description="role_context=manager: only the department manager of the employee's department, only while status=pending_department_manager. Approving forwards to pending_hr (after re-checking the leave balance); rejecting sets rejected_by_manager. role_context=hr: only an HR Manager, only while status=pending_hr. Approving sets status=approved (after checking for overlapping requests and the leave balance) and syncs the used balance; rejecting sets rejected_by_hr.",
     *    tags={"Management Leaves"},
     *    security={{"sanctum":{}}},
     *    @OA\Parameter(
     *      name="id",
     *      in="path",
     *      required=true,
     *      @OA\Schema(type="string", format="uuid")
     *    ),
     *    @OA\RequestBody(
     *      required=true,
     *      @OA\JsonContent(
     *        required={"action","role_context"},
     *        @OA\Property(property="action", type="string", enum={"approve","reject"}),
     *        @OA\Property(property="role_context", type="string", enum={"manager","hr"}),
     *        @OA\Property(property="rejection_reason", type="string", nullable=true, description="Used when action=reject")
     *      )
     *    ),
     *    @OA\Response(
     *      response=200,
     *      description="Action executed successfully",
     *      @OA\JsonContent(
     *        @OA\Property(property="success", type="boolean", example=true),
     *        @OA\Property(property="message", type="string", example="Leave request forwarded to HR."),
     *        @OA\Property(property="data", type="object",
     *          @OA\Property(property="id", type="string", format="uuid"),
     *          @OA\Property(property="status", type="string", example="pending_hr"),
     *          @OA\Property(property="reviewed_by", type="string", format="uuid"),
     *          @OA\Property(property="reviewed_at", type="string", format="date-time")
     *        )
     *      )
     *    ),
     *    @OA\Response(response=403, description="Wrong role for this role_context, or a department manager who does not manage this employee's department"),
     *    @OA\Response(response=404, description="Leave request not found / not in your company"),
     *    @OA\Response(
     *      response=422,
     *      description="Wrong workflow state for this role_context, requested duration exceeds remaining leave balance, or (HR step) overlapping dates with another active leave request",
     *      @OA\JsonContent(
     *        @OA\Property(property="success", type="boolean", example=false),
     *        @OA\Property(property="message", type="string"),
     *        @OA\Property(property="remaining_balance", type="number", nullable=true)
     *      )
     *    )
     * )
     */
    public function action(ManagementLeaveActionRequest $request, string $id): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;

        return DB::transaction(function () use ($request, $id, $user, $companyId) {
            $leaveRequest = LeaveRequest::where('id', $id)
                ->where('company_id', $companyId)
                ->with(['employee.department', 'leaveType'])
                ->lockForUpdate()
                ->firstOrFail();

            $roleContext = $request->validated('role_context');
            $action = $request->validated('action');

            if ($roleContext === 'manager') {
                if ($user?->role !== Role::DepartmentManager->value) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only department managers can perform manager actions.',
                    ], 403);
                }

                if ($leaveRequest->status !== 'pending_department_manager') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Request is not pending department manager review.',
                    ], 422);
                }

                $department = $leaveRequest->employee?->department;

                if (! $department || $department->manager_id !== $user->employee?->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not manage this employee.',
                    ], 403);
                }

                if ($action === 'approve') {
                    $employee = $leaveRequest->employee;
                    $leaveType = $leaveRequest->leaveType;

                    if ($employee && $leaveType) {
                        $year = (int) $leaveRequest->start_date->year;
                        $balance = $this->leaveBalanceService->syncUsedDays($employee, $leaveType, $year);
                        if ((float) $leaveRequest->requested_value > (float) $balance->remaining_days) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Cannot forward: requested duration exceeds remaining leave balance.',
                                'remaining_balance' => max(0, (float) $balance->remaining_days),
                            ], 422);
                        }
                    }

                    $leaveRequest->status = 'pending_hr';
                    $message = 'Leave request forwarded to HR.';
                } else {
                    $leaveRequest->status = 'rejected_by_manager';
                    $leaveRequest->rejection_reason = $request->input('rejection_reason');
                    $message = 'Leave request rejected by department manager.';
                }
            } elseif ($roleContext === 'hr') {
                if ($user?->role !== Role::HrManager->value) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only HR managers can perform HR actions.',
                    ], 403);
                }

                if ($leaveRequest->status !== 'pending_hr') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Request is not pending HR review.',
                    ], 422);
                }

                if ($action === 'approve') {
                    $overlap = $this->leaveBalanceService->hasOverlappingRequest(
                        $leaveRequest->employee_id,
                        $leaveRequest->start_date->toDateString(),
                        $leaveRequest->end_date->toDateString(),
                        $leaveRequest->id,
                    );

                    if ($overlap) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot approve: dates overlap with another active leave request.',
                        ], 422);
                    }

                    $year = (int) $leaveRequest->start_date->year;
                    $employee = $leaveRequest->employee;
                    $leaveType = $leaveRequest->leaveType;

                    if ($employee && $leaveType) {
                        $balance = $this->leaveBalanceService->syncUsedDays($employee, $leaveType, $year);
                        if ((float) $leaveRequest->requested_value > (float) $balance->remaining_days) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Cannot approve: requested duration exceeds remaining leave balance.',
                                'remaining_balance' => max(0, (float) $balance->remaining_days),
                            ], 422);
                        }
                    }

                    $leaveRequest->status = 'approved';
                    $message = 'Leave request approved.';
                } else {
                    $leaveRequest->status = 'rejected_by_hr';
                    $leaveRequest->rejection_reason = $request->input('rejection_reason');
                    $message = 'Leave request rejected by HR.';
                }
            }

            $leaveRequest->reviewed_by = $user->id;
            $leaveRequest->reviewed_at = now();
            $leaveRequest->save();

            // تحديث الرصيد فور الموافقة النهائية من HR
            if ($leaveRequest->status === 'approved' && $leaveRequest->employee && $leaveRequest->leaveType) {
                $this->leaveBalanceService->syncUsedDays(
                    $leaveRequest->employee,
                    $leaveRequest->leaveType,
                    (int) $leaveRequest->start_date->year,
                );
            }

            // Push employee only on final outcomes:
            // - manager reject → notify now
            // - HR approve / HR reject → notify
            // Manager approve (pending_hr) → no notification.
            if (in_array($leaveRequest->status, ['approved', 'rejected_by_hr', 'rejected_by_manager'], true)) {
                try {
                    app(NotificationService::class)->notifyLeaveDecision($leaveRequest);
                } catch (\Throwable $e) {
                    Log::error('Failed to create leave decision notification.', [
                        'leave_request_id' => $leaveRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'id' => $leaveRequest->id,
                    'status' => $leaveRequest->status,
                    'reviewed_by' => $leaveRequest->reviewed_by,
                    'reviewed_at' => $leaveRequest->reviewed_at?->toDateTimeString(),
                ],
            ]);
        });
    }

    private function calculateRemainingBalance(LeaveRequest $leaveRequest): int
    {
        $employee = $leaveRequest->employee;
        $leaveType = $leaveRequest->leaveType;

        if (! $employee || ! $leaveType) {
            return 0;
        }

        $year = $leaveRequest->start_date
            ? (int) $leaveRequest->start_date->year
            : now()->year;

        $balance = $this->leaveBalanceService->syncUsedDays($employee, $leaveType, $year);

        return (int) max(0, round((float) $balance->remaining_days));
    }
}
