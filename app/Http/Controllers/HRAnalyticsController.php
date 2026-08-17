<?php

namespace App\Http\Controllers;

use App\Services\HRAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

/**
 * @OA\Tag(
 *     name="HR Analytics",
 *     description="إحصائيات ومؤشرات الموارد البشرية للمدير العام والـ HR"
 * )
 */
class HRAnalyticsController extends Controller
{
    protected HRAnalyticsService $analyticsService;

    public function __construct(HRAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * التحقق من الصلاحيات بأمان داخل الحساب
     */
    private function authorizeHRorManager(Request $request): void
    {
        $user = $request->user();
        $userRole = strtolower($user->role->name ?? $user->role ?? '');

        if (!in_array($userRole, ['hr_manager', 'general_manager', 'hr', 'company_manager'])) {
            abort(403, 'This action is only available to the company manager and HR manager.');
        }
    }

    /**
     * @OA\Get(
     *     path="/api/analytics/hr/turnover-rate",
     *     summary="استعلام معدل الدوران الوظيفي الربعي (Quarterly Turnover Rate)",
     *     description="يحسب معدل دوران الموظفين. يمكن جلب ربع معين أو إرجاع الأربعة أرباع كاملة للسنة لإظهار المنحنى البياني.",
     *     operationId="getTurnoverRate",
     *     tags={"HR Analytics"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         description="السنة المراد جلب الإحصائية لها (افتراضياً السنة الحالية)",
     *         required=false,
     *         @OA\Schema(type="integer", example=2026)
     *     ),
     *     @OA\Parameter(
     *         name="quarter",
     *         in="query",
     *         description="الربع السنوي (اختياري: 1, 2, 3, 4). في حال عدم تمريره يتم إرجاع الأرباع الأربعة",
     *         required=false,
     *         @OA\Schema(type="integer", enum={1, 2, 3, 4}, example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تمت العملية بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="year", type="integer", example=2026),
     *                 @OA\Property(property="quarters", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="quarter", type="string", example="Q1"),
     *                         @OA\Property(property="quarter_number", type="integer", example=1),
     *                         @OA\Property(property="departed_count", type="integer", example=3),
     *                         @OA\Property(property="average_active_employees", type="number", example=50.5),
     *                         @OA\Property(property="turnover_rate_percentage", type="number", example=5.94)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="غير مصرح بالوصول - التوكن مفقود أو غير صالح"),
     *     @OA\Response(response=403, description="غير مصرح - خاص بالمدير العام والـ HR فقط")
     * )
     */
    public function getTurnoverRate(Request $request): JsonResponse
    {
        $this->authorizeHRorManager($request);

        $year = (int) $request->query('year', Carbon::now()->year);
        $quarter = $request->has('quarter') ? (int) $request->query('quarter') : null;

        $data = $this->analyticsService->getQuarterlyTurnoverRate(
            $request->user()->company_id,
            $year,
            $quarter
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * @OA\Get(
     *     path="/api/analytics/hr/demographics",
     *     summary="استعلام إجمالي القوة البشرية (توزيع الجنس والأعمار)",
     *     description="يعرض توزيع الموظفين الفعالين حسب الجنس والفئات العمرية مع ضمان البنية الثابتة للفرونت إند.",
     *     operationId="getDemographics",
     *     tags={"HR Analytics"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="تمت العملية بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="gender_distribution", type="object",
     *                     @OA\Property(property="male", type="integer", example=30),
     *                     @OA\Property(property="female", type="integer", example=20),
     *                     @OA\Property(property="unspecified", type="integer", example=0)
     *                 ),
     *                 @OA\Property(property="age_distribution", type="object",
     *                     @OA\Property(property="under_25", type="integer", example=5),
     *                     @OA\Property(property="25_34", type="integer", example=25),
     *                     @OA\Property(property="35_44", type="integer", example=15),
     *                     @OA\Property(property="45_54", type="integer", example=4),
     *                     @OA\Property(property="55_plus", type="integer", example=1)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="غير مصرح بالوصول"),
     *     @OA\Response(response=403, description="غير مصرح - خاص بالمدير العام والـ HR فقط")
     * )
     */
    public function getDemographics(Request $request): JsonResponse
    {
        $this->authorizeHRorManager($request);

        $data = $this->analyticsService->getDemographicsAnalytics($request->user()->company_id);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * @OA\Get(
     *     path="/api/analytics/hr/department-budgets",
     *     summary="استعلام الأقسام الأكثر استهلاكاً للميزانية (ترتيب تصاعدي)",
     *     description="يعرض استهلاك الميزانية لرواتب الأقسام لشهر محدد مرتبة تصاعدياً.",
     *     operationId="getDepartmentBudgets",
     *     tags={"HR Analytics"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         description="السنة",
     *         required=false,
     *         @OA\Schema(type="integer", example=2026)
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         description="الشهر (1-12)",
     *         required=false,
     *         @OA\Schema(type="integer", example=8)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تمت العملية بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="department_id", type="string", format="uuid", example="bf5f9cbb-0dae-4..."),
     *                     @OA\Property(property="department_name", type="string", example="الموارد البشرية"),
     *                     @OA\Property(property="total_budget_spent", type="number", example=15000.50)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="غير مصرح بالوصول"),
     *     @OA\Response(response=403, description="غير مصرح - خاص بالمدير العام والـ HR فقط")
     * )
     */
    public function getDepartmentBudgets(Request $request): JsonResponse
    {
        $this->authorizeHRorManager($request);

        $year = $request->query('year', Carbon::now()->year);
        $month = $request->query('month', Carbon::now()->month);

        $data = $this->analyticsService->getDepartmentBudgetConsumption($request->user()->company_id, (int)$year, (int)$month);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * @OA\Get(
     *     path="/api/analytics/hr/daily-verification-rate",
     *     summary="معدل الامتثال اليومي بالبصمة الرقمية (Daily Verification Rate)",
     *     description="يعرض نسبة التحقق الرقمي عبر التطبيق (QR/GPS) مقارنة بالتعديلات والتحققات اليدوية. يدعم تاريخ فردي أو نطاق زمني.",
     *     operationId="getDailyVerificationRate",
     *     tags={"HR Analytics"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="date",
     *         in="query",
     *         description="التاريخ الأساسي أو بداية النطاق بصيغة (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2026-08-01")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="تاريخ نهاية النطاق الزمني لرسومات البيانية (اختياري)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2026-08-07")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تمت العملية بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="start_date", type="string", example="2026-08-01"),
     *                 @OA\Property(property="end_date", type="string", example="2026-08-07"),
     *                 @OA\Property(property="timeline", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="date", type="string", example="2026-08-07"),
     *                         @OA\Property(property="total_attendance_records", type="integer", example=50),
     *                         @OA\Property(property="digital_verifications", type="integer", example=45),
     *                         @OA\Property(property="manual_verifications", type="integer", example=5),
     *                         @OA\Property(property="digital_compliance_rate", type="number", example=90.00),
     *                         @OA\Property(property="manual_rate", type="number", example=10.00)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="غير مصرح بالوصول"),
     *     @OA\Response(response=403, description="غير مصرح - خاص بالمدير العام والـ HR فقط")
     * )
     */
    public function getDailyVerificationRate(Request $request): JsonResponse
    {
        $this->authorizeHRorManager($request);

        $startDate = $request->query('date', $request->query('start_date', Carbon::today()->toDateString()));
        $endDate = $request->query('end_date', null);

        $data = $this->analyticsService->getDailyVerificationRate(
            $request->user()->company_id,
            $startDate,
            $endDate
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * @OA\Get(
     *     path="/api/analytics/hr/realtime-headcount",
     *     summary="عداد حالة القوة البشرية اللحظية (Real-time Headcount)",
     *     description="عداد رقمي مخزن مؤقتاً (Cache) يعرض الحاضرين المتواجدين الآن، المتأخرين، والموظفين في إجازات اليوم.",
     *     operationId="getRealtimeHeadcount",
     *     tags={"HR Analytics"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="تمت العملية بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="timestamp", type="string", format="date-time", example="2026-08-07T20:24:56+02:00"),
     *                 @OA\Property(property="present_now", type="integer", example=35),
     *                 @OA\Property(property="late_today", type="integer", example=4),
     *                 @OA\Property(property="on_leave_today", type="integer", example=3)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="غير مصرح بالوصول"),
     *     @OA\Response(response=403, description="غير مصرح - خاص بالمدير العام والـ HR فقط")
     * )
     */
    public function getRealtimeHeadcount(Request $request): JsonResponse
    {
        $this->authorizeHRorManager($request);

        $data = $this->analyticsService->getRealtimeHeadcount($request->user()->company_id);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * @OA\Get(
     *     path="/api/analytics/hr/performance-distribution",
     *     summary="منحنى تقييم الأداء العام للشركة (Performance Distribution Curve)",
     *     description="توزيع أداء الموظفين حسب مجموع العلامات إلى (ممتاز، جيد، مقبول، ضعيف).",
     *     operationId="getPerformanceDistribution",
     *     tags={"HR Analytics"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         description="السنة المراد عرض التقييم لها",
     *         required=false,
     *         @OA\Schema(type="integer", example=2026)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تمت العملية بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="year", type="integer", example=2026),
     *                 @OA\Property(property="total_evaluations", type="integer", example=100),
     *                 @OA\Property(property="distribution", type="object",
     *                     @OA\Property(property="excellent", type="object",
     *                         @OA\Property(property="count", type="integer", example=25),
     *                         @OA\Property(property="percentage", type="number", example=25.00)
     *                     ),
     *                     @OA\Property(property="good", type="object",
     *                         @OA\Property(property="count", type="integer", example=50),
     *                         @OA\Property(property="percentage", type="number", example=50.00)
     *                     ),
     *                     @OA\Property(property="acceptable", type="object",
     *                         @OA\Property(property="count", type="integer", example=20),
     *                         @OA\Property(property="percentage", type="number", example=20.00)
     *                     ),
     *                     @OA\Property(property="weak", type="object",
     *                         @OA\Property(property="count", type="integer", example=5),
     *                         @OA\Property(property="percentage", type="number", example=5.00)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="غير مصرح بالوصول"),
     *     @OA\Response(response=403, description="غير مصرح - خاص بالمدير العام والـ HR فقط")
     * )
     */
    public function getPerformanceDistribution(Request $request): JsonResponse
    {
        $this->authorizeHRorManager($request);

        $year = $request->query('year', Carbon::now()->year);

        $data = $this->analyticsService->getPerformanceDistribution($request->user()->company_id, (int)$year);

        return response()->json(['success' => true, 'data' => $data]);
    }
}
