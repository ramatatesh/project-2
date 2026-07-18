<?php

namespace App\Http\Controllers;

use App\Models\AttendancePolicy;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySettingsController extends Controller
{
    /**
     * @OA\Get(
     * path="/api/companies/{company}/attendance-policy",
     * summary="عرض سياسة الحضور للشركة",
     * description="يعرض سياسات أوقات العمل والموقع الجغرافي المسجلة للشركة.",
     * tags={"Companies"},
     * security={{"sanctum":{}}},
     * @OA\Parameter(
     * name="company",
     * in="path",
     * required=true,
     * description="معرف الشركة",
     * @OA\Schema(type="string", format="uuid")
     * ),
     * @OA\Response(
     * response=200,
     * description="Attendance policy retrieved successfully"
     * ),
     * @OA\Response(
     * response=403,
     * description="Unauthorized"
     * )
     * )
     */
    public function showAttendancePolicy(Company $company): JsonResponse
    {
        $policy = AttendancePolicy::where('company_id', $company->id)->first();

        return response()->json([
            'success' => true,
            'data'    => $policy,
        ]);
    }

    /**
     * @OA\Put(
     * path="/api/companies/{company}/attendance-policy",
     * summary="تحديث سياسة الحضور",
     * description="يقوم هذا الـ API بحفظ سياسات أوقات العمل للشركة.",
     * tags={"Companies"},
     * security={{"sanctum":{}}},
     * @OA\Parameter(
     * name="company",
     * in="path",
     * required=true,
     * description="معرف الشركة",
     * @OA\Schema(type="string", format="uuid")
     * ),
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"work_start_time","work_end_time","allowed_late_minutes","allowed_early_leave_minutes","allows_overtime"},
     * @OA\Property(property="work_start_time", type="string", example="08:00:00"),
     * @OA\Property(property="work_end_time", type="string", example="17:00:00"),
     * @OA\Property(property="allowed_late_minutes", type="integer", example=15),
     * @OA\Property(property="allowed_early_leave_minutes", type="integer", example=15),
     * @OA\Property(property="allows_overtime", type="boolean", example=true)
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="Attendance policy updated successfully"
     * ),
     * @OA\Response(
     * response=422,
     * description="Validation Error"
     * )
     * )
     */
    public function updateAttendancePolicy(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'work_start_time'              => ['required', 'date_format:H:i:s'],
            'work_end_time'                => ['required', 'date_format:H:i:s'],
            'allowed_late_minutes'         => ['required', 'integer'],
            'allowed_early_leave_minutes'  => ['required', 'integer'],
            'allows_overtime'              => ['required', 'boolean'],
        ]);

        $policy = AttendancePolicy::firstOrCreate(
            ['company_id' => $company->id],
            ['company_id' => $company->id]
        );

        $policy->fill($data)->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث سياسة الحضور بنجاح.',
            'data'    => $policy,
        ]);
    }

    /**
     * @OA\Put(
     * path="/api/companies/{company}/attendance-location",
     * summary="تحديث إعدادات موقع البصمة",
     * description="يقوم هذا الـ API بحفظ إحداثيات المقر الجغرافي ونصف قطر نطاق التواجد المسموح به للموظفين لتسجيل الحضور.",
     * tags={"Companies"},
     * security={{"sanctum":{}}},
     * @OA\Parameter(
     * name="company",
     * in="path",
     * required=true,
     * description="معرف الشركة",
     * @OA\Schema(type="string")
     * ),
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"allowed_perimeter","latitude","longitude"},
     * @OA\Property(property="allowed_perimeter", type="integer", example=150),
     * @OA\Property(property="latitude", type="number", format="float", example=33.513800),
     * @OA\Property(property="longitude", type="number", format="float", example=36.276500)
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="Attendance location updated successfully"
     * ),
     * @OA\Response(
     * response=422,
     * description="Validation Error"
     * )
     * )
     */
    public function updateAttendanceLocation(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'allowed_perimeter' => ['required', 'integer', 'min:10'],
            'latitude'          => ['required', 'numeric', 'between:-90,90'],
            'longitude'         => ['required', 'numeric', 'between:-180,180'],
        ]);

        $policy = AttendancePolicy::firstOrCreate(
            ['company_id' => $company->id],
            ['company_id' => $company->id]
        );

        $policy->fill($data)->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث إعدادات الموقع الجغرافي بنجاح.',
            'data'    => $policy,
        ]);
    }
}

