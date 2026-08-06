<?php

namespace App\Http\Controllers;

use App\Models\AttendancePolicy;
use App\Models\Holiday;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="Employee Company Policies",
 *   description="Read-only 'Company Policies & Holidays' page for the mobile employee app. Always resolves the company from auth()->user()->company_id - no company_id is ever accepted from the client. Available to Employee, HR Manager, Department Manager, and General Manager."
 * )
 */
class EmployeeCompanyPolicyController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/employee/company-policies",
     *   summary="Get the current user's company attendance & leave policies (read-only)",
     *   description="Returns the attendance policy and the currently active leave types for the authenticated user's own company. Never accepts a company_id from the request. If no attendance policy has been configured yet, 'attendance_policy' is null; if there are no active leave types, 'leave_policies' is an empty array - both are still a 200 success response, never an error.",
     *   tags={"Employee Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Company policies retrieved successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="attendance_policy", type="object", nullable=true,
     *           @OA\Property(property="work_start_time", type="string", example="09:00:00"),
     *           @OA\Property(property="work_end_time", type="string", example="17:00:00"),
     *           @OA\Property(property="allowed_late_minutes", type="integer", example=15),
     *           @OA\Property(property="allowed_early_leave_minutes", type="integer", example=15),
     *           @OA\Property(property="minimum_daily_hours", type="number", example=8)
     *         ),
     *         @OA\Property(property="leave_policies", type="array",
     *           @OA\Items(
     *             @OA\Property(property="id", type="string", format="uuid"),
     *             @OA\Property(property="name", type="string", example="Annual Leave"),
     *             @OA\Property(property="allocation_value", type="integer", example=21),
     *             @OA\Property(property="allocation_unit", type="string", example="day"),
     *             @OA\Property(property="requires_proof", type="boolean", example=false)
     *           )
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function policies(): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $attendancePolicy = AttendancePolicy::where('company_id', $companyId)->first();

        $leavePolicies = LeaveType::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $leaveType) => [
                'id' => $leaveType->id,
                'name' => $leaveType->name,
                'allocation_value' => $leaveType->allocation_value,
                'allocation_unit' => $leaveType->allocation_unit,
                'requires_proof' => $leaveType->requires_proof,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'attendance_policy' => $attendancePolicy ? [
                    'work_start_time' => $attendancePolicy->work_start_time,
                    'work_end_time' => $attendancePolicy->work_end_time,
                    'allowed_late_minutes' => $attendancePolicy->allowed_late_minutes,
                    'allowed_early_leave_minutes' => $attendancePolicy->allowed_early_leave_minutes,
                    'minimum_daily_hours' => $attendancePolicy->minimum_daily_hours,
                ] : null,
                'leave_policies' => $leavePolicies,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/employee/company-holidays",
     *   summary="Get the current user's company official holidays (read-only)",
     *   description="Returns the official holidays configured for the authenticated user's own company. Never accepts a company_id from the request. If no holidays are configured yet, returns an empty array - still a 200 success response, never an error.",
     *   tags={"Employee Company Policies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Company holidays retrieved successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array",
     *         @OA\Items(
     *           @OA\Property(property="id", type="string", format="uuid"),
     *           @OA\Property(property="name", type="string", example="عيد الاستقلال"),
     *           @OA\Property(property="start_date", type="string", format="date", example="2026-04-17"),
     *           @OA\Property(property="end_date", type="string", format="date", nullable=true, example=null),
     *           @OA\Property(property="repeats_annually", type="boolean", example=true)
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden role")
     * )
     */
    public function holidays(): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $holidays = Holiday::where('company_id', $companyId)
            ->orderBy('start_date')
            ->get()
            ->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'name' => $holiday->name,
                'start_date' => $holiday->start_date?->toDateString(),
                'end_date' => $holiday->end_date?->toDateString(),
                'repeats_annually' => $holiday->repeats_annually,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $holidays,
        ]);
    }
}
