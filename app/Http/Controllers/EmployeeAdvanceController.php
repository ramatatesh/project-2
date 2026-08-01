<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplySalaryAdvanceRequest;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Employee Advances",
 *   description="Employee salary advance (السُلف المالية) self-service endpoints"
 * )
 */
class EmployeeAdvanceController extends Controller
{
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
     *   @OA\Response(
     *     response=200,
     *     description="Paginated employee advance requests",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="current_page", type="integer"),
     *         @OA\Property(property="data", type="array",
     *           @OA\Items(
     *             @OA\Property(property="id", type="string", format="uuid"),
     *             @OA\Property(property="requested_amount", type="number"),
     *             @OA\Property(property="repayment_months", type="integer"),
     *             @OA\Property(property="monthly_installment", type="number"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="created_at", type="string", format="datetime")
     *           )
     *         ),
     *         @OA\Property(property="last_page", type="integer"),
     *         @OA\Property(property="per_page", type="integer"),
     *         @OA\Property(property="total", type="integer")
     *       )
     *     )
     *   ),
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
     *   @OA\Response(
     *     response=200,
     *     description="Eligibility details",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="basic_salary", type="number", format="float", example=1200.00),
     *         @OA\Property(property="max_allowed_amount", type="number", format="float", example=600.00),
     *         @OA\Property(property="has_active_advance", type="boolean", example=false),
     *         @OA\Property(property="active_advance_details", type="object", nullable=true,
     *           @OA\Property(property="id", type="string", format="uuid"),
     *           @OA\Property(property="requested_amount", type="number", format="float"),
     *           @OA\Property(property="monthly_installment", type="number", format="float"),
     *           @OA\Property(property="repayment_months", type="integer"),
     *           @OA\Property(property="status", type="string")
     *         )
     *       )
     *     )
     *   ),
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
        $policy = SalaryAdvancePolicy::where('company_id', $companyId)->first();

        $maxPercentage = (float) ($policy?->max_advance_percentage ?? 0);
        $maxAllowedAmount = round($basicSalary * $maxPercentage / 100, 2);

        $activeAdvance = SalaryAdvance::where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->with('installments')
            ->get()
            ->first(fn ($advance) => $advance->isActive());

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
     *   @OA\Response(
     *     response=201,
     *     description="Advance request submitted successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string"),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="string", format="uuid"),
     *         @OA\Property(property="requested_amount", type="number"),
     *         @OA\Property(property="repayment_months", type="integer"),
     *         @OA\Property(property="monthly_installment", type="number"),
     *         @OA\Property(property="status", type="string")
     *       )
     *     )
     *   ),
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

        $policy = SalaryAdvancePolicy::where('company_id', $companyId)->first();

        if (! $policy) {
            return response()->json([
                'success' => false,
                'message' => 'Salary advance policy is not configured for your company.',
            ], 422);
        }

        $basicSalary = (float) $employee->base_salary;
        $maxAllowedAmount = round($basicSalary * (float) $policy->max_advance_percentage / 100, 2);
        $requestedAmount = (float) $request->validated('requested_amount');

        if (! $policy->allow_multiple_active_advances) {
            $hasActive = SalaryAdvance::where('employee_id', $employee->id)
                ->where('company_id', $companyId)
                ->with('installments')
                ->get()
                ->contains(fn ($advance) => $advance->isActive());

            if ($hasActive) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يمكنك تقديم طلب سلفة جديد لوجود سلفة نشطة قيد السداد',
                ], 422);
            }
        }

        if ($requestedAmount > $maxAllowedAmount) {
            return response()->json([
                'success' => false,
                'message' => 'Requested amount exceeds the maximum allowed advance amount.',
                'max_allowed_amount' => $maxAllowedAmount,
            ], 422);
        }

        $repaymentMonths = (int) $request->validated('repayment_months');
        $monthlyInstallment = round($requestedAmount / $repaymentMonths, 2);

        $advance = SalaryAdvance::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'requested_amount' => $requestedAmount,
            'repayment_months' => $repaymentMonths,
            'monthly_installment' => $monthlyInstallment,
            'reason' => $request->validated('reason'),
            'status' => SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER,
        ]);

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
