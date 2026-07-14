<?php

namespace App\Http\Controllers;

use App\Models\AttendancePolicy;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySettingsController extends Controller
{
  /**
     * @OA\Put(
     * path="/api/companies/{company}/attendance-policy",
     * summary="تحديث سياسة الحضور والإعدادات الجغرافية للبصمة",
     * description="يقوم هذا الـ API بحفظ سياسات أوقات العمل للشركة بالإضافة إلى إحداثيات المقر الجغرافي ونصف قطر نطاق التواجد المسموح به للموظفين لتسجيل الحضور.",
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
     * required={"work_start_time","work_end_time","allowed_late_minutes","allowed_early_leave_minutes","work_days","minimum_daily_hours","allows_overtime","allowed_perimeter","latitude","longitude"},
     * @OA\Property(property="work_start_time", type="string", example="08:00:00"),
     * @OA\Property(property="work_end_time", type="string", example="17:00:00"),
     * @OA\Property(property="allowed_late_minutes", type="integer", example=15),
     * @OA\Property(property="allowed_early_leave_minutes", type="integer", example=15),
     * @OA\Property(property="work_days", type="array", @OA\Items(type="string", example="monday")),
     * @OA\Property(property="minimum_daily_hours", type="integer", example=8),
     * @OA\Property(property="allows_overtime", type="boolean", example=true),
     * @OA\Property(property="allowed_perimeter", type="integer", example=150),
     * @OA\Property(property="latitude", type="number", format="float", example=33.513800),
     * @OA\Property(property="longitude", type="number", format="float", example=36.276500)
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
    // 1. إضافة حقول الموقع ونطاق البصمة المأخوذة من التصميم إلى الـ Validation
    $data = $request->validate([
        'work_start_time'              => ['required', 'date_format:H:i:s'],
        'work_end_time'                => ['required', 'date_format:H:i:s'],
        'allowed_late_minutes'         => ['required', 'integer'],
        'allowed_early_leave_minutes'  => ['required', 'integer'],
        'work_days'                    => ['required', 'array'],
        'minimum_daily_hours'          => ['required', 'integer'],
        'allows_overtime'              => ['required', 'boolean'],

        // الحقول الجغرافية المضافة بناءً على واجهة الـ Geofencing:
        'allowed_perimeter'            => ['required', 'integer', 'min:10'], // نطاق التواجد (بالمتر)
        'latitude'                     => ['required', 'numeric', 'between:-90,90'], // خط العرض
        'longitude'                    => ['required', 'numeric', 'between:-180,180'], // خط الطول
    ]);

    // 2. جلب السياسة الحالية للشركة أو إنشائها إذا لم تكن موجودة
    $policy = AttendancePolicy::firstOrCreate(
        ['company_id' => $company->id],
        ['company_id' => $company->id]
    );

    // 3. تخزين كافة البيانات (حقول الوقت + حقول الـ Geofencing) داخل الموديل
    $policy->fill($data)->save();

    return response()->json([
        'success' => true,
        'message' => 'تم تحديث سياسة الحضور وإعدادات النطاق الجغرافي بنجاح.',
        'data'    => $policy,
    ]);
}
}

