<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkLeaveTypeRequest;
use App\Http\Requests\PayrollSettingsRequest;
use App\Http\Requests\SalaryRuleRequest;
use App\Models\Company;
use App\Models\LeaveType;
use App\Models\SalaryRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Company Policies",
 *   description="Endpoints for leave types, salary rules and payroll settings for a tenant company"
 * )
 */
class CompanyPolicyController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/companies/{company}/leave-types",
     *   summary="List leave types for a company",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Leave types retrieved successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   )
     * )
     */
    public function indexLeaveTypes(Company $company): JsonResponse
    {
        $leaveTypes = $company->leaveTypes()->get();

        return response()->json([
            'success' => true,
            'data' => $leaveTypes,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/leave-types",
     *   summary="Create multiple leave types for a company",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"leave_types"},
     *       @OA\Property(
     *         property="leave_types",
     *         type="array",
     *         @OA\Items(
     *           type="object",
     *           required={"name","allocation_value","allocation_unit"},
     *           @OA\Property(property="name", type="string", example="Annual Leave"),
     *           @OA\Property(property="allocation_value", type="integer", example=21),
     *           @OA\Property(property="allocation_unit", type="string", example="days"),
     *           @OA\Property(property="requires_proof", type="boolean", example=false),
     *           @OA\Property(property="is_active", type="boolean", example=true)
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Leave types created successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   )
     * )
     */
    public function storeLeaveTypes(BulkLeaveTypeRequest $request, Company $company): JsonResponse
    {
        $items = $request->validated()['leave_types'];
        $leaveTypes = [];

        DB::transaction(function () use ($items, $company, &$leaveTypes) {
            foreach ($items as $item) {
                $leaveTypes[] = LeaveType::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $company->id,
                    'name' => $item['name'],
                    'allocation_value' => $item['allocation_value'],
                    'allocation_unit' => $item['allocation_unit'],
                    'requires_proof' => $item['requires_proof'] ?? false,
                    'is_active' => $item['is_active'] ?? true,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'data' => $leaveTypes,
        ], 201);
    }

    /**
     * @OA\Put(
     *   path="/api/companies/{company}/leave-types",
     *   summary="Update multiple leave types for a company",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"leave_types"},
     *       @OA\Property(
     *         property="leave_types",
     *         type="array",
     *         @OA\Items(
     *           type="object",
     *           required={"name","allocation_value","allocation_unit"},
     *           @OA\Property(property="id", type="string", format="uuid", example="00000000-0000-0000-0000-000000000000"),
     *           @OA\Property(property="name", type="string", example="Annual Leave"),
     *           @OA\Property(property="allocation_value", type="integer", example=21),
     *           @OA\Property(property="allocation_unit", type="string", example="days"),
     *           @OA\Property(property="requires_proof", type="boolean", example=false),
     *           @OA\Property(property="is_active", type="boolean", example=true)
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Leave types updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   )
     * )
     */
    public function updateLeaveTypes(BulkLeaveTypeRequest $request, Company $company): JsonResponse
    {
        $items = $request->validated()['leave_types'];
        $leaveTypes = [];

        DB::transaction(function () use ($items, $company, &$leaveTypes) {
            foreach ($items as $item) {
                $data = [
                    'name' => $item['name'],
                    'allocation_value' => $item['allocation_value'],
                    'allocation_unit' => $item['allocation_unit'],
                    'requires_proof' => $item['requires_proof'] ?? false,
                    'is_active' => $item['is_active'] ?? true,
                ];

                if (! empty($item['id'])) {
                    $leaveType = LeaveType::where('id', $item['id'])
                        ->where('company_id', $company->id)
                        ->first();

                    if ($leaveType) {
                        $leaveType->update($data);
                        $leaveTypes[] = $leaveType->fresh();
                        continue;
                    }
                }

                $leaveTypes[] = LeaveType::create(array_merge($data, [
                    'id' => Str::uuid()->toString(),
                    'company_id' => $company->id,
                ]));
            }
        });

        return response()->json(['success' => true, 'data' => $leaveTypes]);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/leave-types/{leaveType}/toggle",
     *   summary="Toggle leave type active state",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="leaveType",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Leave type toggled successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function toggleLeaveType(Company $company, LeaveType $leaveType): JsonResponse
    {
        if ($leaveType->company_id !== $company->id) {
            return response()->json(['success' => false, 'message' => 'Leave type not found for this company.'], 404);
        }

        $leaveType->update(['is_active' => ! $leaveType->is_active]);

        return response()->json(['success' => true, 'data' => $leaveType]);
    }

    /**
     * @OA\Get(
     *   path="/api/companies/{company}/salary-rules",
     *   summary="List salary rules for a company",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Salary rules retrieved successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   )
     * )
     */
    public function indexSalaryRules(Company $company): JsonResponse
    {
        $rules = $company->salaryRules()->get();

        return response()->json(['success' => true, 'data' => $rules]);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/salary-rules",
     *   summary="Create a salary rule for a company",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"rule_type","operation","value_type","value"},
     *       @OA\Property(property="rule_type", type="string", example="late"),
     *       @OA\Property(property="time_unit", type="string", example="day"),
     *       @OA\Property(property="operation", type="string", example="deduction"),
     *       @OA\Property(property="value_type", type="string", example="fixed"),
     *       @OA\Property(property="value", type="number", format="float", example=50),
     *       @OA\Property(property="is_active", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Salary rule created successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function storeSalaryRule(SalaryRuleRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $rule = SalaryRule::create(array_merge($data, [
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'is_active' => $data['is_active'] ?? true,
        ]));

        return response()->json(['success' => true, 'data' => $rule], 201);
    }

    /**
     * @OA\Put(
     *   path="/api/companies/{company}/salary-rules/{rule}",
     *   summary="Update a salary rule for a company",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="rule",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       @OA\Property(property="rule_type", type="string", example="late"),
     *       @OA\Property(property="time_unit", type="string", example="day"),
     *       @OA\Property(property="operation", type="string", example="deduction"),
     *       @OA\Property(property="value_type", type="string", example="fixed"),
     *       @OA\Property(property="value", type="number", format="float", example=50),
     *       @OA\Property(property="is_active", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Salary rule updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function updateSalaryRule(SalaryRuleRequest $request, Company $company, SalaryRule $rule): JsonResponse
    {
        if ($rule->company_id !== $company->id) {
            return response()->json(['success' => false, 'message' => 'Salary rule not found for this company.'], 404);
        }

        $rule->update($request->validated());

        return response()->json(['success' => true, 'data' => $rule]);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/salary-rules/{rule}/toggle",
     *   summary="Toggle salary rule active state",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="rule",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Salary rule toggled successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function toggleSalaryRule(Company $company, SalaryRule $rule): JsonResponse
    {
        if ($rule->company_id !== $company->id) {
            return response()->json(['success' => false, 'message' => 'Salary rule not found for this company.'], 404);
        }

        $rule->update(['is_active' => ! $rule->is_active]);

        return response()->json(['success' => true, 'data' => $rule]);
    }

    /**
     * @OA\Put(
     *   path="/api/companies/{company}/payroll-currency",
     *   summary="Update company payroll currency",
     *   tags={"Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"payroll_currency"},
     *       @OA\Property(property="payroll_currency", type="string", example="USD")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Payroll currency updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function updatePayrollCurrency(PayrollSettingsRequest $request, Company $company): JsonResponse
    {
        $company->update(['payroll_currency' => $request->validated()['payroll_currency']]);

        return response()->json(['success' => true, 'data' => $company]);
    }
}
