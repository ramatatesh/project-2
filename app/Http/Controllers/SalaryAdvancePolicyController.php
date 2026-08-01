<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSalaryAdvancePolicyRequest;
use App\Models\Company;
use App\Models\SalaryAdvancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Salary Advance Policies",
 *   description="Company-level salary advance (السَّلف) policy configuration"
 * )
 */
class SalaryAdvancePolicyController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/companies/{company}/advance-policy",
     *   summary="Retrieve the current salary advance policy for a company",
     *   tags={"Salary Advance Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Company salary advance policy",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object", nullable=true,
     *         @OA\Property(property="id", type="string", format="uuid"),
     *         @OA\Property(property="company_id", type="string", format="uuid"),
     *         @OA\Property(property="max_advance_percentage", type="number", format="float", example=50.00),
     *         @OA\Property(property="max_repayment_months", type="integer", example=6),
     *         @OA\Property(property="allow_multiple_active_advances", type="boolean", example=false)
     *       )
     *     )
     *   ),
     *   @OA\Response(response=403, description="Forbidden (not a member of this company)")
     * )
     */
    public function show(Company $company): JsonResponse
    {
        $policy = SalaryAdvancePolicy::where('company_id', $company->id)->first();

        return response()->json([
            'success' => true,
            'data' => $policy,
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/companies/{company}/advance-policy",
     *   summary="Create or update the salary advance policy for a company",
     *   tags={"Salary Advance Policies"},
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
     *       required={"max_advance_percentage","max_repayment_months","allow_multiple_active_advances"},
     *       @OA\Property(property="max_advance_percentage", type="number", format="float", example=50.00),
     *       @OA\Property(property="max_repayment_months", type="integer", example=6),
     *       @OA\Property(property="allow_multiple_active_advances", type="boolean", example=false)
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Policy created or updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string"),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="string", format="uuid"),
     *         @OA\Property(property="company_id", type="string", format="uuid"),
     *         @OA\Property(property="max_advance_percentage", type="number", format="float"),
     *         @OA\Property(property="max_repayment_months", type="integer"),
     *         @OA\Property(property="allow_multiple_active_advances", type="boolean")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=422, description="Validation failed"),
     *   @OA\Response(response=403, description="Forbidden (General Manager only / not a member of this company)")
     * )
     */
    public function storeOrUpdate(StoreSalaryAdvancePolicyRequest $request, Company $company): JsonResponse
    {
        $validated = $request->validated();

        $policy = SalaryAdvancePolicy::updateOrCreate(
            ['company_id' => $company->id],
            [
                'id' => (SalaryAdvancePolicy::where('company_id', $company->id)->value('id')) ?? Str::uuid()->toString(),
                'max_advance_percentage' => $validated['max_advance_percentage'],
                'max_repayment_months' => $validated['max_repayment_months'],
                'allow_multiple_active_advances' => $validated['allow_multiple_active_advances'],
            ]
        );

        $message = ($policy->wasRecentlyCreated)
            ? 'Salary advance policy created successfully.'
            : 'Salary advance policy updated successfully.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $policy->fresh(),
        ]);
    }
}
