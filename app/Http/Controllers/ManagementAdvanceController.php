<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ManagementAdvanceActionRequest;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Services\SalaryAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *   name="Management Advances",
 *   description="Salary advance (السُلف المالية) approval workflow for department managers and HR"
 * )
 */
class ManagementAdvanceController extends Controller
{
    public function __construct(
        private readonly SalaryAdvanceService $salaryAdvanceService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/management/advances",
     *   summary="List salary advance requests for managers and HR",
     *   tags={"Management Advances"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="status",
     *     in="query",
     *     required=false,
     *     @OA\Schema(type="string", enum={"pending","pending_department_manager","pending_hr","approved","rejected_by_manager","rejected_by_hr","paid_off"})
     *   ),
     *   @OA\Parameter(
     *     name="per_page",
     *     in="query",
     *     required=false,
     *     @OA\Schema(type="integer", default=15)
     *   ),
     *   @OA\Response(response=200, description="Paginated advance requests")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;
        $employee = $user?->employee;
        $userRole = $user?->role;

        $query = SalaryAdvance::query()
            ->where('company_id', $companyId)
            ->with(['employee.user', 'employee.department']);

        $status = $request->input('status');

        if ($userRole === Role::DepartmentManager->value) {
            $managedDepartmentIds = DB::table('departments')
                ->where('manager_id', $employee?->id)
                ->pluck('id');

            $query->whereHas('employee', function ($q) use ($managedDepartmentIds) {
                $q->whereIn('department_id', $managedDepartmentIds);
            });

            // Default inbox: only waiting for this manager.
            if (! $status) {
                $query->where('status', SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER);
            }
        } elseif ($userRole === Role::HrManager->value) {
            // Default inbox: only waiting for HR (like leave inbox).
            if (! $status) {
                $query->where('status', SalaryAdvance::STATUS_PENDING_HR);
            }
        }

        if ($status) {
            if ($status === 'pending') {
                $query->whereIn('status', [
                    SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER,
                    SalaryAdvance::STATUS_PENDING_HR,
                ]);
            } elseif ($status === 'approved') {
                $query->whereIn('status', [SalaryAdvance::STATUS_APPROVED, SalaryAdvance::STATUS_PAID_OFF]);
            } else {
                $query->where('status', $status);
            }
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        $paginator->getCollection()->transform(function (SalaryAdvance $advance) {
            return [
                'id' => $advance->id,
                'employee_name' => $advance->employee?->user?->full_name,
                'department_name' => $advance->employee?->department?->name,
                'requested_amount' => $advance->requested_amount,
                'monthly_installment' => $advance->monthly_installment,
                'repayment_months' => $advance->repayment_months,
                'status' => $advance->status,
                'rejection_reason' => $advance->rejection_reason,
                'created_at' => $advance->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/management/advances/{id}",
     *   summary="Get detailed information about a salary advance request",
     *   tags={"Management Advances"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Advance request details"),
     *   @OA\Response(response=403, description="Access denied"),
     *   @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;
        $employee = $user?->employee;
        $userRole = $user?->role;

        $advance = SalaryAdvance::where('id', $id)
            ->where('company_id', $companyId)
            ->with(['employee.user', 'employee.department', 'installments', 'approvingManager.user', 'approvingHr.user'])
            ->firstOrFail();

        if ($userRole === Role::DepartmentManager->value) {
            $managedDepartmentIds = DB::table('departments')
                ->where('manager_id', $employee?->id)
                ->pluck('id');

            $employeeDepartmentId = $advance->employee?->department_id;

            if (! $employeeDepartmentId || ! $managedDepartmentIds->contains($employeeDepartmentId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied.',
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeAdvanceDetails($advance),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/management/advances/{id}/action",
     *   summary="Execute a workflow action on a salary advance request",
     *   tags={"Management Advances"},
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
     *       @OA\Property(property="rejection_reason", type="string", nullable=true)
     *     )
     *   ),
     *   @OA\Response(response=200, description="Action executed successfully"),
     *   @OA\Response(response=403, description="Unauthorized role or company mismatch"),
     *   @OA\Response(response=404, description="Salary advance request not found"),
     *   @OA\Response(response=422, description="Invalid workflow state")
     * )
     */
    public function action(ManagementAdvanceActionRequest $request, string $id): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;
        $employee = $user?->employee;

        $roleContext = $request->validated('role_context');
        $action = $request->validated('action');
        $rejectionReason = $request->validated('rejection_reason');

        try {
            $result = DB::transaction(function () use ($id, $companyId, $employee, $user, $roleContext, $action, $rejectionReason) {
                $advance = SalaryAdvance::where('id', $id)
                    ->where('company_id', $companyId)
                    ->with(['employee.department', 'installments'])
                    ->lockForUpdate()
                    ->first();

                if (! $advance) {
                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'Salary advance request not found.',
                    ];
                }

                if ($roleContext === 'manager') {
                    if ($user?->role !== Role::DepartmentManager->value) {
                        return [
                            'error' => true,
                            'status' => 403,
                            'message' => 'Only department managers can perform manager actions.',
                        ];
                    }

                    if ($advance->status !== SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER) {
                        return [
                            'error' => true,
                            'status' => 422,
                            'message' => 'Advance request is not pending department manager review.',
                        ];
                    }

                    $department = $advance->employee?->department;

                    if (! $department || $department->manager_id !== $employee?->id) {
                        return [
                            'error' => true,
                            'status' => 403,
                            'message' => 'You do not manage this employee.',
                        ];
                    }

                    if ($action === 'approve') {
                        $advance->status = SalaryAdvance::STATUS_PENDING_HR;
                        $advance->approved_by_manager_id = $employee?->id;
                        $advance->rejection_reason = null;
                        $message = 'Advance request forwarded to HR.';
                    } else {
                        $advance->status = SalaryAdvance::STATUS_REJECTED_BY_MANAGER;
                        $advance->rejection_reason = $rejectionReason;
                        $message = 'Advance request rejected by department manager.';
                    }
                } elseif ($roleContext === 'hr') {
                    if ($user?->role !== Role::HrManager->value) {
                        return [
                            'error' => true,
                            'status' => 403,
                            'message' => 'Only HR managers can perform HR actions.',
                        ];
                    }

                    if ($advance->status !== SalaryAdvance::STATUS_PENDING_HR) {
                        return [
                            'error' => true,
                            'status' => 422,
                            'message' => 'Advance request is not pending HR review.',
                        ];
                    }

                    if ($action === 'approve') {
                        $validation = $this->salaryAdvanceService->validateForApproval($advance);
                        if (! $validation['ok']) {
                            return [
                                'error' => true,
                                'status' => 422,
                                'message' => $validation['message'],
                                'extra' => array_filter([
                                    'max_allowed_amount' => $validation['max_allowed_amount'] ?? null,
                                ]),
                            ];
                        }

                        $advance->status = SalaryAdvance::STATUS_APPROVED;
                        $advance->approved_by_hr_id = $employee?->id;
                        $advance->rejection_reason = null;
                        $advance->save();

                        // Always regenerate inside the same transaction to avoid partial schedules.
                        $this->salaryAdvanceService->regenerateInstallments($advance);
                        $message = 'Advance request approved.';
                    } else {
                        $advance->status = SalaryAdvance::STATUS_REJECTED_BY_HR;
                        $advance->rejection_reason = $rejectionReason;
                        $message = 'Advance request rejected by HR.';
                    }
                } else {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'Invalid role context.',
                    ];
                }

                $advance->save();

                return [
                    'error' => false,
                    'message' => $message,
                    'advance' => $advance,
                ];
            });
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to process advance action.',
            ], 500);
        }

        if ($result['error'] ?? false) {
            $payload = [
                'success' => false,
                'message' => $result['message'],
            ];

            if (! empty($result['extra'])) {
                $payload = array_merge($payload, $result['extra']);
            }

            return response()->json($payload, $result['status'] ?? 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'id' => $result['advance']->id,
                'status' => $result['advance']->status,
                'rejection_reason' => $result['advance']->rejection_reason,
            ],
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/management/advances/{id}/installments/{installmentId}/pay",
     *   summary="Mark a salary advance installment as paid",
     *   tags={"Management Advances"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="installmentId",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Installment marked as paid"),
     *   @OA\Response(response=403, description="HR only"),
     *   @OA\Response(response=422, description="Invalid installment state")
     * )
     */
    public function markInstallmentPaid(string $id, string $installmentId): JsonResponse
    {
        $user = auth()->user();

        if ($user?->role !== Role::HrManager->value) {
            return response()->json([
                'success' => false,
                'message' => 'Only HR managers can mark installments as paid.',
            ], 403);
        }

        $advance = SalaryAdvance::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        if (! in_array($advance->status, [SalaryAdvance::STATUS_APPROVED, SalaryAdvance::STATUS_PAID_OFF], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only approved advances can receive installment payments.',
            ], 422);
        }

        $installment = SalaryAdvanceInstallment::where('id', $installmentId)
            ->where('salary_advance_id', $advance->id)
            ->firstOrFail();

        if ($installment->status === SalaryAdvanceInstallment::STATUS_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'Installment is already paid.',
            ], 422);
        }

        $updatedAdvance = DB::transaction(function () use ($installment) {
            return $this->salaryAdvanceService->markInstallmentPaid(
                SalaryAdvanceInstallment::where('id', $installment->id)->lockForUpdate()->firstOrFail()
            );
        });

        return response()->json([
            'success' => true,
            'message' => $updatedAdvance->status === SalaryAdvance::STATUS_PAID_OFF
                ? 'Installment paid. Advance is fully paid off.'
                : 'Installment marked as paid.',
            'data' => $this->serializeAdvanceDetails(
                $updatedAdvance->load(['employee.user', 'employee.department', 'approvingManager.user', 'approvingHr.user'])
            ),
        ]);
    }

    private function serializeAdvanceDetails(SalaryAdvance $advance): array
    {
        return [
            'id' => $advance->id,
            'employee' => [
                'id' => $advance->employee?->id,
                'full_name' => $advance->employee?->user?->full_name,
                'job_title' => $advance->employee?->job_title,
                'department_name' => $advance->employee?->department?->name,
                'basic_salary' => $advance->employee?->base_salary,
            ],
            'requested_amount' => $advance->requested_amount,
            'repayment_months' => $advance->repayment_months,
            'monthly_installment' => $advance->monthly_installment,
            'reason' => $advance->reason,
            'rejection_reason' => $advance->rejection_reason,
            'status' => $advance->status,
            'approved_by_manager' => $advance->approvingManager?->user?->full_name,
            'approved_by_hr' => $advance->approvingHr?->user?->full_name,
            'created_at' => $advance->created_at?->toDateTimeString(),
            'installments' => $advance->installments->map(function ($installment) {
                return [
                    'id' => $installment->id,
                    'due_date' => $installment->due_date?->toDateString(),
                    'amount' => $installment->amount,
                    'status' => $installment->status,
                    'paid_at' => $installment->paid_at?->toDateTimeString(),
                ];
            })->values(),
        ];
    }
}
