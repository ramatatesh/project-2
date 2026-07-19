<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ManagementLeaveActionRequest;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="Management Leaves",
 *   description="Leave request approval workflow for department managers and HR"
 * )
 */
class ManagementLeaveController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/management/leaves/inbox",
     *   summary="HR inbox for leave requests awaiting HR decision",
     *   tags={"Management Leaves"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Pending HR leave requests",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array",
     *         @OA\Items(
     *           @OA\Property(property="id", type="string", format="uuid"),
     *           @OA\Property(property="employee_name", type="string"),
     *           @OA\Property(property="leave_type_name", type="string"),
     *           @OA\Property(property="start_date", type="string", format="date"),
     *           @OA\Property(property="end_date", type="string", format="date"),
     *           @OA\Property(property="duration_days", type="integer"),
     *           @OA\Property(property="reason", type="string", nullable=true),
     *           @OA\Property(property="remaining_balance_days", type="integer")
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=403, description="Forbidden (HR only)")
     * )
     */
    public function inbox(): JsonResponse
    {
        $user = auth()->user();

        if ($user?->role !== Role::HrManager->value) {
            return response()->json([
                'success' => false,
                'message' => 'HR access only.',
            ], 403);
        }

        $companyId = $user->company_id;

        $requests = LeaveRequest::where('company_id', $companyId)
            ->where('status', 'pending_hr')
            ->with(['employee.user', 'leaveType', 'employee.department'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (LeaveRequest $leaveRequest) {
                return [
                    'id' => $leaveRequest->id,
                    'employee_name' => $leaveRequest->employee?->user?->full_name,
                    'leave_type_name' => $leaveRequest->leaveType?->name,
                    'start_date' => $leaveRequest->start_date?->toDateString(),
                    'end_date' => $leaveRequest->end_date?->toDateString(),
                    'duration_days' => (int) $leaveRequest->requested_value,
                    'reason' => $leaveRequest->reason,
                    'remaining_balance_days' => $this->calculateRemainingBalance($leaveRequest),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/management/leaves/{id}/action",
     *   summary="Execute a workflow action on a leave request",
     *   tags={"Management Leaves"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"action","role_context"},
     *       @OA\Property(property="action", type="string", enum={"approve","reject"}),
     *       @OA\Property(property="role_context", type="string", enum={"manager","hr"}),
     *       @OA\Property(property="rejection_reason", type="string", nullable=true, example="Insufficient coverage")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Action executed successfully"
     *   ),
     *   @OA\Response(response=403, description="Unauthorized role or company mismatch"),
     *   @OA\Response(response=422, description="Invalid workflow state")
     * )
     */
    public function action(ManagementLeaveActionRequest $request, string $id): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;

        $leaveRequest = LeaveRequest::where('id', $id)
            ->where('company_id', $companyId)
            ->with('employee.department')
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
                $leaveRequest->status = 'approved';
                $message = 'Leave request approved.';
            } else {
                $leaveRequest->status = 'rejected_by_hr';
                $leaveRequest->rejection_reason = $request->input('rejection_reason');
                $message = 'Leave request rejected by HR.';
            }
        }

        $leaveRequest->save();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'id' => $leaveRequest->id,
                'status' => $leaveRequest->status,
            ],
        ]);
    }

    private function calculateRemainingBalance(LeaveRequest $leaveRequest): int
    {
        $used = LeaveRequest::where('employee_id', $leaveRequest->employee_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->where('status', 'approved')
            ->whereYear('start_date', now()->year)
            ->sum('requested_value');

        return (int) max(0, ($leaveRequest->leaveType?->allocation_value ?? 0) - $used);
    }
}
