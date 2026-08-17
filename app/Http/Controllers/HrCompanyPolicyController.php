<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="HR Company Policies",
 *   description="Read-only company policy endpoints for HR Manager and General Manager. Always scoped to auth()->user()->company_id — no company_id is accepted from the client."
 * )
 */
class HrCompanyPolicyController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/hr/company-policies/leave-types",
     *   summary="List leave types for the HR user's company (read-only)",
     *   tags={"HR Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Leave types retrieved successfully"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function leaveTypes(): JsonResponse
    {
        return app(CompanyPolicyController::class)->indexLeaveTypes($this->company());
    }

    /**
     * @OA\Get(
     *   path="/api/hr/company-policies/salary-rules",
     *   summary="List salary rules for the HR user's company (read-only)",
     *   tags={"HR Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Salary rules retrieved successfully"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function salaryRules(): JsonResponse
    {
        return app(CompanyPolicyController::class)->indexSalaryRules($this->company());
    }

    /**
     * @OA\Get(
     *   path="/api/hr/company-policies/holidays",
     *   summary="List holidays for the HR user's company (read-only)",
     *   tags={"HR Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Holidays retrieved successfully"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function holidays(): JsonResponse
    {
        return app(HolidayPolicyController::class)->indexHolidays($this->company());
    }

    /**
     * @OA\Get(
     *   path="/api/hr/company-policies/weekly-holidays",
     *   summary="List weekly holidays for the HR user's company (read-only)",
     *   tags={"HR Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Weekly holidays retrieved successfully"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function weeklyHolidays(): JsonResponse
    {
        return app(HolidayPolicyController::class)->indexWeeklyHolidays($this->company());
    }

    /**
     * @OA\Get(
     *   path="/api/hr/company-policies/evaluation-policy",
     *   summary="Get evaluation policy for the HR user's company (read-only)",
     *   tags={"HR Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Evaluation policy retrieved successfully"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function evaluationPolicy(): JsonResponse
    {
        return app(HolidayPolicyController::class)->indexEvaluationPolicy($this->company());
    }

    /**
     * @OA\Get(
     *   path="/api/hr/company-policies/attendance-policy",
     *   summary="Get attendance policy for the HR user's company (read-only)",
     *   tags={"HR Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Attendance policy retrieved successfully"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function attendancePolicy(): JsonResponse
    {
        return app(CompanySettingsController::class)->showAttendancePolicy($this->company());
    }

    /**
     * @OA\Get(
     *   path="/api/hr/company-policies/advance-policy",
     *   summary="Get salary advance policy for the HR user's company (read-only)",
     *   tags={"HR Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Advance policy retrieved successfully"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function advancePolicy(): JsonResponse
    {
        return app(SalaryAdvancePolicyController::class)->show($this->company());
    }

    private function company(): Company
    {
        return Company::findOrFail(auth()->user()->company_id);
    }
}
