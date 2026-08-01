<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ManagementAdvanceActionRequest;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Management Advances",
 *   description="Salary advance (السُلف المالية) approval workflow for department managers and HR"
 * )
 */
class ManagementAdvanceController extends Controller
{
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
     *   @OA\Response(
     *     response=200,
     *     description="Paginated advance requests",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="current_page", type="integer"),
     *         @OA\Property(property="data", type="array",
     *           @OA\Items(
     *             @OA\Property(property="id", type="string", format="uuid"),
     *             @OA\Property(property="employee_name", type="string"),
     *             @OA\Property(property="department_name", type="string", nullable=true),
     *             @OA\Property(property="requested_amount", type="number"),
     *             @OA\Property(property="monthly_installment", type="number"),
     *             @OA\Property(property="repayment_months", type="integer"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="created_at", type="string", format="datetime")
     *           )
     *         ),
     *         @OA\Property(property="last_page", type="integer"),
     *         @OA\Property(property="per_page", type="integer"),
     *         @OA\Property(property="total", type="integer")
     *       )
     *     )
     *   )
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

        if ($userRole === Role::DepartmentManager->value) {
            $managedDepartmentIds = DB::table('departments')
                ->where('manager_id', $employee?->id)
                ->pluck('id');

            $query->whereHas('employee', function ($q) use ($managedDepartmentIds) {
                $q->whereIn('department_id', $managedDepartmentIds);
            });

            $query->where('status', SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER);
        }

        $status = $request->input('status');

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
     *   @OA\Response(
     *     response=200,
     *     description="Advance request details",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="string", format="uuid"),
     *         @OA\Property(property="employee", type="object",
     *           @OA\Property(property="id", type="string", format="uuid"),
     *           @OA\Property(property="full_name", type="string"),
     *           @OA\Property(property="job_title", type="string"),
     *           @OA\Property(property="department_name", type="string", nullable=true),
     *           @OA\Property(property="basic_salary", type="number")
     *         ),
     *         @OA\Property(property="requested_amount", type="number"),
     *         @OA\Property(property="repayment_months", type="integer"),
     *         @OA\Property(property="monthly_installment", type="number"),
     *         @OA\Property(property="reason", type="string", nullable=true),
     *         @OA\Property(property="status", type="string"),
     *         @OA\Property(property="approved_by_manager", type="string", nullable=true),
     *         @OA\Property(property="approved_by_hr", type="string", nullable=true),
     *         @OA\Property(property="created_at", type="string", format="datetime"),
     *         @OA\Property(property="installments", type="array",
     *           @OA\Items(
     *             @OA\Property(property="id", type="string", format="uuid"),
     *             @OA\Property(property="due_date", type="string", format="date"),
     *             @OA\Property(property="amount", type="number"),
     *             @OA\Property(property="status", type="string")
     *           )
     *         )
     *       )
     *     )
     *   ),
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

        $data = [
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
                ];
            }),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
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
     *   @OA\Response(
     *     response=200,
     *     description="Action executed successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string"),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="string", format="uuid"),
     *         @OA\Property(property="status", type="string")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=403, description="Unauthorized role or company mismatch"),
     *   @OA\Response(response=422, description="Invalid workflow state")
     * )
     */
    public function action(ManagementAdvanceActionRequest $request, string $id): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id;
        $employee = $user?->employee;

        $advance = SalaryAdvance::where('id', $id)
            ->where('company_id', $companyId)
            ->with(['employee.department', 'installments'])
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

            if ($advance->status !== SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER) {
                return response()->json([
                    'success' => false,
                    'message' => 'Advance request is not pending department manager review.',
                ], 422);
            }

            $department = $advance->employee?->department;

            if (! $department || $department->manager_id !== $employee?->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not manage this employee.',
                ], 403);
            }

            if ($action === 'approve') {
                $advance->status = SalaryAdvance::STATUS_PENDING_HR;
                $advance->approved_by_manager_id = $employee?->id;
                $message = 'Advance request forwarded to HR.';
            } else {
                $advance->status = SalaryAdvance::STATUS_REJECTED_BY_MANAGER;
                $message = 'Advance request rejected by department manager.';
            }
        } elseif ($roleContext === 'hr') {
            if ($user?->role !== Role::HrManager->value) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only HR managers can perform HR actions.',
                ], 403);
            }

            if ($advance->status !== SalaryAdvance::STATUS_PENDING_HR) {
                return response()->json([
                    'success' => false,
                    'message' => 'Advance request is not pending HR review.',
                ], 422);
            }

            if ($action === 'approve') {
                $advance->status = SalaryAdvance::STATUS_APPROVED;
                $advance->approved_by_hr_id = $employee?->id;
                $message = 'Advance request approved.';

                if ($advance->installments->isEmpty()) {
                    $this->generateInstallments($advance);
                }
            } else {
                $advance->status = SalaryAdvance::STATUS_REJECTED_BY_HR;
                $message = 'Advance request rejected by HR.';
            }
        }

        $advance->save();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'id' => $advance->id,
                'status' => $advance->status,
            ],
        ]);
    }

    private function generateInstallments(SalaryAdvance $advance): void
    {
        $repaymentMonths = (int) $advance->repayment_months;
        $baseInstallment = (float) $advance->monthly_installment;
        $total = (float) $advance->requested_amount;
        $remaining = $total;

        for ($i = 1; $i <= $repaymentMonths; $i++) {
            if ($i === $repaymentMonths) {
                $amount = $remaining;
            } else {
                $amount = $baseInstallment;
            }

            $remaining -= $amount;

            SalaryAdvanceInstallment::create([
                'id' => Str::uuid()->toString(),
                'salary_advance_id' => $advance->id,
                'due_date' => Carbon::now()->addMonthNoOverflow($i)->startOfMonth()->toDateString(),
                'amount' => round($amount, 2),
                'status' => 'pending',
            ]);
        }
    }
}
