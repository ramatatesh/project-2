<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ManagementOvertimeActionRequest;
use App\Models\OvertimeRequest;
use App\Services\NotificationService;
use App\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *   name="Management Overtime",
 *   description="Overtime approval workflow for department managers and HR"
 * )
 */
class ManagementOvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeService $overtimeService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/management/overtime",
     *   summary="List overtime requests awaiting review (manager/HR inbox)",
     *   tags={"Management Overtime"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="status",
     *     in="query",
     *     required=false,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Paginated overtime requests")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;
        $employee = $user?->employee;
        $role = $user?->role;
        $status = $request->input('status');

        $query = OvertimeRequest::query()
            ->where('company_id', $companyId)
            ->with(['employee.user', 'employee.department']);

        if ($role === Role::DepartmentManager->value) {
            $managedDepartmentIds = DB::table('departments')
                ->where('manager_id', $employee?->id)
                ->pluck('id');

            $query->whereHas('employee', function ($q) use ($managedDepartmentIds) {
                $q->whereIn('department_id', $managedDepartmentIds);
            });

            if (! $status) {
                $query->where('status', OvertimeRequest::STATUS_PENDING_DEPARTMENT_MANAGER);
            }
        } elseif ($role === Role::HrManager->value) {
            if (! $status) {
                $query->where('status', OvertimeRequest::STATUS_PENDING_HR);
            }
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized role for overtime inbox.',
            ], 403);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        $paginator->getCollection()->transform(function (OvertimeRequest $ot) {
            $previewAmount = null;
            if ($ot->employee) {
                $preview = $this->overtimeService->preview(
                    $ot->employee,
                    $ot->duration_type ?? OvertimeRequest::DURATION_HOUR,
                    (float) $ot->approvedUnits()
                );
                $previewAmount = $preview['ok'] ? $preview['estimated_amount'] : null;
            }

            return [
                'id' => $ot->id,
                'employee_name' => $ot->employee?->user?->full_name,
                'department_name' => $ot->employee?->department?->name,
                'request_date' => $ot->request_date?->toDateString(),
                'duration_type' => $ot->duration_type,
                'units_requested' => $ot->hours_requested,
                'units_approved' => $ot->hours_approved,
                'reason' => $ot->reason,
                'status' => $ot->status,
                'estimated_amount' => $previewAmount,
                'calculated_amount' => $ot->calculated_amount,
                'created_at' => $ot->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/management/overtime/{id}",
     *   summary="Get overtime request details",
     *   tags={"Management Overtime"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     description="Overtime request UUID",
     *     @OA\Schema(type="string", format="uuid", example="bf5f9cbb-0dae-407e-8d49-712d0089a21a")
     *   ),
     *   @OA\Response(response=200, description="Overtime request details"),
     *   @OA\Response(response=404, description="Overtime request not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        $user = auth()->user();

        $ot = OvertimeRequest::where('id', $id)
            ->where('company_id', $user?->company_id)
            ->with(['employee.user', 'employee.department', 'deptManager', 'hrManager'])
            ->firstOrFail();

        if ($user?->role === Role::DepartmentManager->value) {
            $managedDepartmentIds = DB::table('departments')
                ->where('manager_id', $user->employee?->id)
                ->pluck('id');

            $deptId = $ot->employee?->department_id;
            if (! $deptId || ! $managedDepartmentIds->contains($deptId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied.',
                ], 403);
            }
        }

        $preview = $ot->employee
            ? $this->overtimeService->preview(
                $ot->employee,
                $ot->duration_type ?? OvertimeRequest::DURATION_HOUR,
                (float) $ot->approvedUnits()
            )
            : ['ok' => false];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $ot->id,
                'employee' => [
                    'id' => $ot->employee?->id,
                    'full_name' => $ot->employee?->user?->full_name,
                    'job_title' => $ot->employee?->job_title,
                    'department_name' => $ot->employee?->department?->name,
                    'basic_salary' => $ot->employee?->base_salary,
                ],
                'request_date' => $ot->request_date?->toDateString(),
                'duration_type' => $ot->duration_type,
                'units_requested' => $ot->hours_requested,
                'units_approved' => $ot->hours_approved,
                'reason' => $ot->reason,
                'status' => $ot->status,
                'rejection_reason' => $ot->rejection_reason,
                'review_notes' => $ot->review_notes,
                'estimated_amount' => ($preview['ok'] ?? false) ? $preview['estimated_amount'] : null,
                'calculated_amount' => $ot->calculated_amount,
                'approved_by_manager' => $ot->deptManager?->full_name,
                'approved_by_hr' => $ot->hrManager?->full_name,
                'dept_approved_at' => $ot->dept_approved_at?->toDateTimeString(),
                'hr_registered_at' => $ot->hr_registered_at?->toDateTimeString(),
                'created_at' => $ot->created_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/management/overtime/{id}/action",
     *   summary="Approve or reject an overtime request",
     *   tags={"Management Overtime"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     description="Overtime request UUID",
     *     @OA\Schema(type="string", format="uuid", example="bf5f9cbb-0dae-407e-8d49-712d0089a21a")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"action","role_context"},
     *       @OA\Property(property="action", type="string", enum={"approve","reject"}),
     *       @OA\Property(property="role_context", type="string", enum={"manager","hr"}),
     *       @OA\Property(property="hours_approved", type="integer", nullable=true),
     *       @OA\Property(property="rejection_reason", type="string", nullable=true),
     *       @OA\Property(property="review_notes", type="string", nullable=true)
     *     )
     *   ),
     *   @OA\Response(response=200, description="Action executed successfully")
     * )
     */
    public function action(ManagementOvertimeActionRequest $request, string $id): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;
        $employee = $user?->employee;
        $roleContext = $request->validated('role_context');
        $action = $request->validated('action');

        try {
            $result = DB::transaction(function () use ($id, $companyId, $user, $employee, $roleContext, $action, $request) {
                $ot = OvertimeRequest::where('id', $id)
                    ->where('company_id', $companyId)
                    ->with(['employee.department'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($roleContext === 'manager') {
                    if ($user?->role !== Role::DepartmentManager->value) {
                        return ['error' => true, 'status' => 403, 'message' => 'Only department managers can perform manager actions.'];
                    }

                    if ($ot->status !== OvertimeRequest::STATUS_PENDING_DEPARTMENT_MANAGER) {
                        return ['error' => true, 'status' => 422, 'message' => 'Request is not pending department manager review.'];
                    }

                    $department = $ot->employee?->department;
                    if (! $department || $department->manager_id !== $employee?->id) {
                        return ['error' => true, 'status' => 403, 'message' => 'You do not manage this employee.'];
                    }

                    if ($action === 'approve') {
                        $approvedUnits = (int) ($request->validated('hours_approved') ?? $ot->hours_requested);
                        if ($approvedUnits > (int) $ot->hours_requested) {
                            return ['error' => true, 'status' => 422, 'message' => 'Approved units cannot exceed requested units.'];
                        }

                        $ot->status = OvertimeRequest::STATUS_PENDING_HR;
                        $ot->dept_manager_approval = $user->id;
                        $ot->dept_approved_at = now();
                        $ot->hours_approved = $approvedUnits;
                        $ot->rejection_reason = null;
                        $ot->review_notes = $request->validated('review_notes');
                        $message = 'Overtime request forwarded to HR.';
                    } else {
                        $ot->status = OvertimeRequest::STATUS_REJECTED_BY_MANAGER;
                        $ot->dept_manager_approval = $user->id;
                        $ot->dept_approved_at = now();
                        $ot->rejection_reason = $request->validated('rejection_reason');
                        $ot->review_notes = $request->validated('review_notes');
                        $message = 'Overtime request rejected by department manager.';
                    }
                } elseif ($roleContext === 'hr') {
                    if ($user?->role !== Role::HrManager->value) {
                        return ['error' => true, 'status' => 403, 'message' => 'Only HR managers can perform HR actions.'];
                    }

                    if ($ot->status !== OvertimeRequest::STATUS_PENDING_HR) {
                        return ['error' => true, 'status' => 422, 'message' => 'Request is not pending HR review.'];
                    }

                    if ($action === 'approve') {
                        if ($request->filled('hours_approved')) {
                            $approvedUnits = (int) $request->validated('hours_approved');
                            $managerApproved = (int) ($ot->hours_approved ?? $ot->hours_requested);
                            if ($approvedUnits > $managerApproved) {
                                return ['error' => true, 'status' => 422, 'message' => 'HR approval cannot exceed the hours approved by the department manager ('.$managerApproved.').'];
                            }
                            $ot->hours_approved = $approvedUnits;
                        }

                        $units = (float) $ot->approvedUnits();
                        $employeeModel = $ot->employee;
                        if (! $employeeModel) {
                            return ['error' => true, 'status' => 422, 'message' => 'Employee record not found for this request.'];
                        }

                        $rule = $this->overtimeService->activeRuleFor($ot->company_id, $ot->duration_type ?? OvertimeRequest::DURATION_HOUR);
                        if (! $rule) {
                            return ['error' => true, 'status' => 422, 'message' => 'Overtime salary rule is not configured.'];
                        }

                        $amount = $this->overtimeService->calculateAmount($employeeModel, $rule, $units);

                        $ot->status = OvertimeRequest::STATUS_APPROVED;
                        $ot->hr_registered_by = $user->id;
                        $ot->hr_registered_at = now();
                        $ot->calculated_amount = $amount;
                        $ot->rejection_reason = null;
                        $ot->review_notes = $request->validated('review_notes');
                        $ot->save();

                        $this->overtimeService->applyToSalaryRecord($ot);
                        $message = 'Overtime request approved and salary updated.';
                    } else {
                        $ot->status = OvertimeRequest::STATUS_REJECTED_BY_HR;
                        $ot->hr_registered_by = $user->id;
                        $ot->hr_registered_at = now();
                        $ot->rejection_reason = $request->validated('rejection_reason');
                        $ot->review_notes = $request->validated('review_notes');
                        $message = 'Overtime request rejected by HR.';
                    }
                } else {
                    return ['error' => true, 'status' => 422, 'message' => 'Invalid role context.'];
                }

                $ot->save();

                return ['error' => false, 'message' => $message, 'overtime' => $ot];
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to process overtime action.',
            ], 500);
        }

        if ($result['error'] ?? false) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        $overtime = $result['overtime'];
        // Final outcomes only: manager reject, HR approve, HR reject.
        // Manager approve (pending_hr) does not notify the employee.
        if (in_array($overtime->status, [
            OvertimeRequest::STATUS_APPROVED,
            OvertimeRequest::STATUS_REJECTED_BY_HR,
            OvertimeRequest::STATUS_REJECTED_BY_MANAGER,
        ], true)) {
            try {
                app(NotificationService::class)->notifyOvertimeDecision($overtime);
            } catch (\Throwable $e) {
                Log::error('Failed to create overtime decision notification.', [
                    'overtime_request_id' => $overtime->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'id' => $overtime->id,
                'status' => $overtime->status,
                'units_approved' => $overtime->hours_approved,
                'calculated_amount' => $overtime->calculated_amount,
                'rejection_reason' => $overtime->rejection_reason,
            ],
        ]);
    }
}
