<?php

namespace App\Http\Controllers;

use App\Http\Requests\EvaluationPolicyRequest;
use App\Http\Requests\HolidayPolicyRequest;
use App\Http\Requests\HolidayRequest;
use App\Models\Company;
use App\Models\EvaluationPolicy;
use App\Models\Holiday;
use App\Models\HolidayPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Holiday Policies",
 *   description="Endpoints for company holiday and evaluation policy management"
 * )
 */
class HolidayPolicyController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/companies/{company}/holidays",
     *   summary="List holidays for a company",
     *   tags={"Holiday Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Holidays retrieved successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   )
     * )
     */
    public function indexHolidays(Company $company): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $company->holidays()->get(),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/holidays",
     *   summary="Create a holiday for a company",
     *   tags={"Holiday Policies"},
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
     *       required={"name","holiday_type","start_date","repeats_annually"},
     *       @OA\Property(property="name", type="string", example="عيد الاستقلال"),
     *       @OA\Property(property="holiday_type", type="string", example="single_day"),
     *       @OA\Property(property="start_date", type="string", format="date", example="2026-10-01"),
     *       @OA\Property(property="end_date", type="string", format="date", example="2026-10-02"),
     *       @OA\Property(property="repeats_annually", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Holiday created successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function storeHoliday(HolidayRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $holiday = Holiday::create(array_merge($data, [
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'end_date' => $data['holiday_type'] === 'single_day' ? $data['start_date'] : ($data['end_date'] ?? $data['start_date']),
            'is_default' => false,
        ]));

        return response()->json(['success' => true, 'data' => $holiday], 201);
    }

    /**
     * @OA\Put(
     *   path="/api/companies/{company}/holidays/{holiday}",
     *   summary="Update a holiday for a company",
     *   tags={"Holiday Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="holiday",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","holiday_type","start_date","repeats_annually"},
     *       @OA\Property(property="name", type="string", example="عيد الاستقلال"),
     *       @OA\Property(property="holiday_type", type="string", example="single_day"),
     *       @OA\Property(property="start_date", type="string", format="date", example="2026-10-01"),
     *       @OA\Property(property="end_date", type="string", format="date", example="2026-10-02"),
     *       @OA\Property(property="repeats_annually", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Holiday updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function updateHoliday(HolidayRequest $request, Company $company, Holiday $holiday): JsonResponse
    {
        if ($holiday->company_id !== $company->id) {
            return response()->json(['success' => false, 'message' => 'Holiday not found for this company.'], 404);
        }

        $data = $request->validated();
        $holiday->update(array_merge($data, [
            'end_date' => $data['holiday_type'] === 'single_day' ? $data['start_date'] : ($data['end_date'] ?? $data['start_date']),
        ]));

        return response()->json(['success' => true, 'data' => $holiday]);
    }

    /**
     * @OA\Delete(
     *   path="/api/companies/{company}/holidays/{holiday}",
     *   summary="Delete a holiday for a company",
     *   tags={"Holiday Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="holiday",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Holiday deleted successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="Holiday deleted successfully.")
     *     )
     *   )
     * )
     */
    public function deleteHoliday(Company $company, Holiday $holiday): JsonResponse
    {
        if ($holiday->company_id !== $company->id) {
            return response()->json(['success' => false, 'message' => 'Holiday not found for this company.'], 404);
        }

        $holiday->delete();

        return response()->json(['success' => true, 'message' => 'Holiday deleted successfully.']);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/holidays/defaults",
     *   summary="Add default Syrian holidays for a company",
     *   tags={"Holiday Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Default Syrian holidays added successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="Default Syrian holidays added successfully."),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   )
     * )
     */
    public function addDefaultSyrianHolidays(Company $company): JsonResponse
    {
        $defaults = [
            ['name' => 'رأس السنة الميلادية', 'holiday_type' => 'single_day', 'start_date' => '2024-01-01', 'repeats_annually' => true],
            ['name' => 'عيد العمال', 'holiday_type' => 'single_day', 'start_date' => '2024-05-01', 'repeats_annually' => true],
            ['name' => 'عيد الشهداء', 'holiday_type' => 'single_day', 'start_date' => '2024-03-08', 'repeats_annually' => true],
            ['name' => 'عيد التحرير', 'holiday_type' => 'single_day', 'start_date' => '2024-04-17', 'repeats_annually' => true],
            ['name' => 'عيد الأم', 'holiday_type' => 'single_day', 'start_date' => '2024-03-21', 'repeats_annually' => true],
            ['name' => 'عيد بداية الثورة', 'holiday_type' => 'single_day', 'start_date' => '2024-03-15', 'repeats_annually' => true],
        ];

        foreach ($defaults as $holiday) {
            Holiday::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'name' => $holiday['name'],
                    'start_date' => $holiday['start_date'],
                ],
                array_merge($holiday, [
                    'id' => Str::uuid()->toString(),
                    'company_id' => $company->id,
                    'end_date' => $holiday['start_date'],
                    'is_default' => true,
                ])
            );
        }

        return response()->json(['success' => true, 'message' => 'Default Syrian holidays added successfully.', 'data' => $company->holidays()->get()]);
    }

    /**
     * @OA\Delete(
     *   path="/api/companies/{company}/holidays/defaults",
     *   summary="Remove default Syrian holidays for a company",
     *   tags={"Holiday Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Default Syrian holidays removed successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="Default Syrian holidays removed successfully.")
     *     )
     *   )
     * )
     */
    public function removeDefaultSyrianHolidays(Company $company): JsonResponse
    {
        $company->holidays()->where('is_default', true)->delete();

        return response()->json(['success' => true, 'message' => 'Default Syrian holidays removed successfully.']);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/weekly-holidays",
     *   summary="Update weekly holidays for a company",
     *   tags={"Holiday Policies"},
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
     *       required={"weekly_holidays"},
     *       @OA\Property(property="weekly_holidays", type="array", @OA\Items(type="string", example="friday"))
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Weekly holidays updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function updateWeeklyHolidays(HolidayPolicyRequest $request, Company $company): JsonResponse
    {
        $policy = HolidayPolicy::firstOrCreate(
            ['company_id' => $company->id],
            ['company_id' => $company->id]
        );

        $policy->update(['weekly_holidays' => $request->validated()['weekly_holidays']]);

        return response()->json(['success' => true, 'data' => $policy]);
    }

    /**
     * @OA\Get(
     *   path="/api/companies/{company}/weekly-holidays",
     *   summary="Get weekly holiday settings for a company",
     *   tags={"Holiday Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Weekly holiday settings retrieved successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function indexWeeklyHolidays(Company $company): JsonResponse
    {
        $policy = HolidayPolicy::firstOrCreate(
            ['company_id' => $company->id],
            ['company_id' => $company->id, 'weekly_holidays' => []]
        );

        return response()->json(['success' => true, 'data' => $policy]);
    }

    /**
     * @OA\Put(
     *   path="/api/companies/{company}/evaluation-policy",
     *   summary="Update evaluation policy for a company",
     *   tags={"Evaluation Policies"},
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
     *       required={"apply_review_to_salary"},
     *       @OA\Property(property="weekly_review_period", type="string", example="monthly"),
     *       @OA\Property(property="apply_review_to_salary", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Evaluation policy updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function updateEvaluationPolicy(EvaluationPolicyRequest $request, Company $company): JsonResponse
    {
        $policy = EvaluationPolicy::firstOrCreate(
            ['company_id' => $company->id],
            ['company_id' => $company->id]
        );

        $policy->update($request->validated());

        return response()->json(['success' => true, 'data' => $policy]);
    }

    /**
 * @OA\Get(
 *   path="/api/companies/{company}/evaluation-policy",
 *   summary="Get evaluation policy for a company",
 *   tags={"Evaluation Policies"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(
 *     name="company",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="string", format="uuid")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Evaluation policy retrieved successfully",
 *     @OA\JsonContent(
 *    required={"apply_review_to_salary"},
 *     @OA\Property(
 *      property="apply_review_to_salary",
 *  type="boolean",
* example=true
* ),
* @OA\Property(
* property="excellent_bonus_percent",
* type="number",
*example=10
* ),
* @OA\Property(
* property="good_bonus_percent",
* type="number",
* example=5
* ),
* @OA\Property(
* property="poor_deduction_percent",
* type="number",
* example=3
*        )
*       )
 *     )
 *   )
 * )
 */
public function indexEvaluationPolicy(Company $company): JsonResponse
{
    $policy = EvaluationPolicy::firstOrCreate(
    ['company_id' => $company->id],
    [
        'company_id' => $company->id,
        'apply_review_to_salary' => false,
        'excellent_bonus_percent' => 0,
        'good_bonus_percent' => 0,
        'poor_deduction_percent' => 0,
    ]
    );

    return response()->json([
    'success' => true,
    'data' => [
        'company_id' => $policy->company_id,

        'apply_review_to_salary' => $policy->apply_review_to_salary,

        'excellent_bonus_percent' => $policy->excellent_bonus_percent,
        'good_bonus_percent' => $policy->good_bonus_percent,
        'poor_deduction_percent' => $policy->poor_deduction_percent,

        // ثوابت النظام
        'manager_weight' => 60,
        'peer_weight' => 30,
        'self_weight' => 10,
    ]
    ]);
}
}
