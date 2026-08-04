<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplySalaryAdvanceRequest;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Services\SalaryAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Employee Advances",
 *   description="Employee salary advance (السُلف المالية) self-service endpoints"
 * )
 */
class EmployeeAdvanceController extends Controller
{
    public function __construct(
        private readonly SalaryAdvanceService $salaryAdvanceService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/employee/advances",
     *   summary="List the logged-in employee's salary advance requests",
     *   tags={"Employee Advances"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="per_page",
     *     in="query",
     *     required=false,
     *     @OA\Schema(type="integer", default=15)
     *   ),
     *   @OA\Response(response=200, description="Paginated employee advance requests"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $paginator = SalaryAdvance::where('employee_id', $employee->id)
            ->where('company_id', $user->company_id)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->through(function (SalaryAdvance $advance) {
                return [
                    'id' => $advance->id,
                    'requested_amount' => $advance->requested_amount,
                    'repayment_months' => $advance->repayment_months,
                    'monthly_installment' => $advance->monthly_installment,
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
     *   path="/api/employee/advances/eligibility",
     *   summary="Check current salary advance eligibility",
     *   tags={"Employee Advances"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Eligibility details"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found")
     * )
     */
    public function eligibility(): JsonResponse
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

        $basicSalary = (float) $employee->base_salary;
        $policy = $this->salaryAdvanceService->policyForCompany($companyId);
        $maxAllowedAmount = $policy
            ? $this->salaryAdvanceService->maxAllowedAmount($employee, $policy)
            : 0.0;

        $activeAdvance = $this->salaryAdvanceService->findActiveAdvance($employee->id, $companyId);
        $hasDepartmentManager = $this->salaryAdvanceService->employeeHasDepartmentManager($employee);

        $activeDetails = null;
        if ($activeAdvance) {
            $activeDetails = [
                'id' => $activeAdvance->id,
                'requested_amount' => $activeAdvance->requested_amount,
                'monthly_installment' => $activeAdvance->monthly_installment,
                'repayment_months' => $activeAdvance->repayment_months,
                'status' => $activeAdvance->status,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'basic_salary' => $basicSalary,
                'max_allowed_amount' => $maxAllowedAmount,
                'max_repayment_months' => $policy?->max_repayment_months,
                'allow_multiple_active_advances' => (bool) ($policy?->allow_multiple_active_advances ?? false),
                'policy_configured' => $policy !== null,
                'has_department_manager' => $hasDepartmentManager,
                'has_active_advance' => $activeAdvance !== null,
                'active_advance_details' => $activeDetails,
            ],
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/employee/advances/apply",
     *   summary="Submit a salary advance request",
     *   tags={"Employee Advances"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"requested_amount","repayment_months"},
     *       @OA\Property(property="requested_amount", type="number", format="float", example=500.00),
     *       @OA\Property(property="repayment_months", type="integer", example=2),
     *       @OA\Property(property="reason", type="string", nullable=true, example="Emergency medical expense")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Advance request submitted successfully"),
     *   @OA\Response(response=422, description="Validation failed or business rule violation"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found")
     * )
     */
    public function apply(ApplySalaryAdvanceRequest $request): JsonResponse
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

        if (! $employee->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Inactive employees cannot apply for a salary advance.',
            ], 422);
        }

        if (! $this->salaryAdvanceService->employeeHasDepartmentManager($employee)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot apply: your department has no assigned manager.',
            ], 422);
        }

        $policy = $this->salaryAdvanceService->policyForCompany($companyId);

        if (! $policy) {
            return response()->json([
                'success' => false,
                'message' => 'Salary advance policy is not configured for your company.',
            ], 422);
        }

        $maxAllowedAmount = $this->salaryAdvanceService->maxAllowedAmount($employee, $policy);
        $requestedAmount = (float) $request->validated('requested_amount');

        if ($requestedAmount > $maxAllowedAmount) {
            return response()->json([
                'success' => false,
                'message' => 'Requested amount exceeds the maximum allowed advance amount.',
                'max_allowed_amount' => $maxAllowedAmount,
            ], 422);
        }

        $repaymentMonths = (int) $request->validated('repayment_months');
        $monthlyInstallment = round($requestedAmount / $repaymentMonths, 2);

        // Lock the (always-existing) employee row first so concurrent apply() requests for the
        // same employee are serialized - this prevents two simultaneous requests from both
        // passing the "no active advance" check before either one has been created.
        $advance = DB::transaction(function () use ($employee, $companyId, $policy, $requestedAmount, $repaymentMonths, $monthlyInstallment, $request) {
            Employee::where('id', $employee->id)->lockForUpdate()->first();

            if (! $policy->allow_multiple_active_advances
                && $this->salaryAdvanceService->hasActiveAdvance($employee->id, $companyId)
            ) {
                return null;
            }

            return SalaryAdvance::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'requested_amount' => $requestedAmount,
                'repayment_months' => $repaymentMonths,
                'monthly_installment' => $monthlyInstallment,
                'reason' => $request->validated('reason'),
                'status' => SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER,
            ]);
        });

        if (! $advance) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك تقديم طلب سلفة جديد لوجود سلفة نشطة قيد السداد',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Salary advance request submitted successfully.',
            'data' => [
                'id' => $advance->id,
                'requested_amount' => $advance->requested_amount,
                'repayment_months' => $advance->repayment_months,
                'monthly_installment' => $advance->monthly_installment,
                'status' => $advance->status,
            ],
        ], 201);
    }
}
