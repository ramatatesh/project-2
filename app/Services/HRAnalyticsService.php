<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class HRAnalyticsService
{
    /**
     * 1. أ) استعلام معدل الدوران الوظيفي (Quarterly Turnover Rate)
     */
    public function getQuarterlyTurnoverRate(string $companyId, int $year, int $quarter): array
    {
        $startMonth = ($quarter - 1) * 3 + 1;
        $startDate = Carbon::create($year, $startMonth, 1)->startOfMonth();
        $endDate = $startDate->copy()->addMonths(2)->endOfMonth();

        // 1. عدد الموظفين غير الفعالين المضافين خلال الربع السنوي
        $departedCount = DB::table('employees')
            ->where('company_id', $companyId)
            ->where('is_active', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // 2. الموظفون الفعالون في بداية ونهاية الربع السنوي بناءً على hire_date و is_active
        $startActive = DB::table('employees')
            ->where('company_id', $companyId)
            ->where('hire_date', '<=', $startDate)
            ->where('is_active', true)
            ->count();

        $endActive = DB::table('employees')
            ->where('company_id', $companyId)
            ->where('hire_date', '<=', $endDate)
            ->where('is_active', true)
            ->count();

        $averageActive = ($startActive + $endActive) / 2;

        $turnoverRate = $averageActive > 0 ? round(($departedCount / $averageActive) * 100, 2) : 0;

        return [
            'year' => $year,
            'quarter' => $quarter,
            'departed_count' => $departedCount,
            'average_active_employees' => $averageActive,
            'turnover_rate_percentage' => $turnoverRate,
        ];
    }

    /**
     * 1. ب) استعلام إجمالي القوة البشرية (توزيع الجنس والأعمار)
     */
    public function getDemographicsAnalytics(string $companyId): array
    {
        // 1. توزيع الجنس عبر الربط مع جدول users
        $genderDistribution = DB::table('employees')
            ->join('users', 'employees.user_id', '=', 'users.id')
            ->where('employees.company_id', $companyId)
            ->where('employees.is_active', true)
            ->selectRaw("COALESCE(users.gender, 'unspecified') as gender, count(*) as count")
            ->groupBy('users.gender')
            ->pluck('count', 'gender')
            ->toArray();

        // 2. توزيع الفئات العمرية عبر الربط مع جدول users باستخدام birth_date
        $ageDistribution = DB::table('employees')
            ->join('users', 'employees.user_id', '=', 'users.id')
            ->where('employees.company_id', $companyId)
            ->where('employees.is_active', true)
            ->whereNotNull('users.birth_date')
            ->selectRaw("
                CASE
                    WHEN EXTRACT(YEAR FROM age(users.birth_date)) < 25 THEN 'under_25'
                    WHEN EXTRACT(YEAR FROM age(users.birth_date)) BETWEEN 25 AND 34 THEN '25_34'
                    WHEN EXTRACT(YEAR FROM age(users.birth_date)) BETWEEN 35 AND 44 THEN '35_44'
                    WHEN EXTRACT(YEAR FROM age(users.birth_date)) BETWEEN 45 AND 54 THEN '45_54'
                    ELSE '55_and_above'
                END as age_group,
                count(*) as count
            ")
            ->groupBy('age_group')
            ->pluck('count', 'age_group')
            ->toArray();

        return [
            'gender_distribution' => $genderDistribution,
            'age_distribution' => [
                'under_25' => $ageDistribution['under_25'] ?? 0,
                '25_34'    => $ageDistribution['25_34'] ?? 0,
                '35_44'    => $ageDistribution['35_44'] ?? 0,
                '45_54'    => $ageDistribution['45_54'] ?? 0,
                '55_plus'  => $ageDistribution['55_and_above'] ?? 0,
            ],
        ];
    }

    /**
     * 1. ج) استعلام الأقسام الأكثر استهلاكاً للميزانية (ترتيب تصاعدي)
     */
    public function getDepartmentBudgetConsumption(string $companyId, int $year, int $month): array
    {
        return DB::table('departments')
            ->where('departments.company_id', $companyId)
            ->leftJoin('employees', 'departments.id', '=', 'employees.department_id')
            ->leftJoin('salary_records', function ($join) use ($year, $month) {
                $join->on('employees.id', '=', 'salary_records.employee_id')
                     ->where('salary_records.year', '=', $year)
                     ->where('salary_records.month', '=', $month);
            })
            ->select(
                'departments.id as department_id',
                'departments.name as department_name',
                DB::raw('COALESCE(SUM(salary_records.net_salary), 0) as total_budget_spent')
            )
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('total_budget_spent', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'department_id' => $item->department_id,
                    'department_name' => $item->department_name,
                    'total_budget_spent' => (float) $item->total_budget_spent,
                ];
            })
            ->toArray();
    }

    /**
     * 2. أ) معدل الامتثال اليومي بالبصمة الرقمية (Daily Verification Rate)
     */
    public function getDailyVerificationRate(string $companyId, string $date): array
    {
        // جلب الحركات وسجلات التعديل اليدوي المرتبطة بها
        $records = DB::table('attendance_records')
            ->where('company_id', $companyId)
            ->where('work_date', $date)
            ->select('id', 'check_in_lat', 'qr_token_used', 'check_in_device_id')
            ->get();

        $total = $records->count();

        if ($total === 0) {
            return [
                'date' => $date,
                'total_attendance_records' => 0,
                'digital_verifications' => 0,
                'manual_verifications' => 0,
                'digital_compliance_rate' => 0,
                'manual_rate' => 0,
            ];
        }

        // السجلات التي تم تعديلها يدوياً بواسطة HR من جدول attendance_adjustments
        $adjustedRecordIds = DB::table('attendance_adjustments')
            ->where('company_id', $companyId)
            ->pluck('attendance_record_id')
            ->toArray();

        $manualCount = 0;
        $digitalCount = 0;

        foreach ($records as $record) {
            // إذا كان السجل موجوداً في جدول التعديلات اليدوية يعتبر manual
            if (in_array($record->id, $adjustedRecordIds)) {
                $manualCount++;
            }
            // إذا استخدم GPS أو QR Token أو Device ID يعتبر تحقق رقمي digital
            elseif (!empty($record->check_in_lat) || !empty($record->qr_token_used) || !empty($record->check_in_device_id)) {
                $digitalCount++;
            }
            else {
                $manualCount++;
            }
        }

        return [
            'date' => $date,
            'total_attendance_records' => $total,
            'digital_verifications' => $digitalCount,
            'manual_verifications' => $manualCount,
            'digital_compliance_rate' => round(($digitalCount / $total) * 100, 2),
            'manual_rate' => round(($manualCount / $total) * 100, 2),
        ];
    }

    /**
     * 2. ب) عداد حالة القوة البشرية اللحظية (Real-time Headcount)
     */
    public function getRealtimeHeadcount(string $companyId): array
    {
        $cacheKey = "realtime_headcount_{$companyId}";

        return Cache::remember($cacheKey, 60, function () use ($companyId) {
            $today = Carbon::today()->toDateString();

            // 1. الحاضرون الآن (قاموا بـ check_in_time ولم يقوموا بـ check_out_time)
            $presentNow = DB::table('attendance_records')
                ->where('company_id', $companyId)
                ->where('work_date', $today)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->count();

            // 2. المتأخرون اليوم
            $lateToday = DB::table('attendance_records')
                ->where('company_id', $companyId)
                ->where('work_date', $today)
                ->where('late_minutes', '>', 0)
                ->count();

            // 3. الموظفون في إجازات معتمدة اليوم (استخدام leave_requests كبديل لـ leaves)
            $onLeaveToday = 0;

            // التحقق من وجود الجدول لمنع أخطاء 500 في حال اختلاف اسم الجدول
            $leaveTableName = Schema::hasTable('leave_requests') ? 'leave_requests' : (Schema::hasTable('leaves') ? 'leaves' : null);

            if ($leaveTableName) {
                $onLeaveToday = DB::table($leaveTableName)
                    ->where('company_id', $companyId)
                    ->where('status', 'approved')
                    ->whereDate('start_date', '<=', $today)
                    ->whereDate('end_date', '>=', $today)
                    ->count();
            }

            return [
                'timestamp' => Carbon::now()->toIso8601String(),
                'present_now' => $presentNow,
                'late_today' => $lateToday,
                'on_leave_today' => $onLeaveToday,
            ];
        });
    }

    /**
 * 3. أ) منحنى تقييم الأداء العام للشركة (Performance Distribution Curve)
 */
public function getPerformanceDistribution(string $companyId, ?int $year = null): array
{
    $year = $year ?? Carbon::now()->year;

    $scores = [];

    // 1. الخيار الأول (الأكثر دقة): القراءة المباشرة من جدول النتيجة النهائية evaluation_scores
    if (Schema::hasTable('evaluation_scores')) {
        $scores = DB::table('evaluation_scores')
            ->join('evaluation_cycles', 'evaluation_scores.evaluation_cycle_id', '=', 'evaluation_cycles.id')
            ->where('evaluation_scores.company_id', $companyId)
            ->whereYear('evaluation_cycles.created_at', $year)
            ->whereNotNull('evaluation_scores.final_score')
            ->selectRaw("
                CASE
                    WHEN evaluation_scores.final_score >= 90 THEN 'excellent'
                    WHEN evaluation_scores.final_score BETWEEN 75 AND 89.99 THEN 'good'
                    WHEN evaluation_scores.final_score BETWEEN 60 AND 74.99 THEN 'acceptable'
                    ELSE 'weak'
                END as performance_level,
                COUNT(*) as count
            ")
            ->groupBy('performance_level')
            ->pluck('count', 'performance_level')
            ->toArray();
    }

    // 2. الخيار الثاني (Fallback): القراءة من جدول المراجعات evaluation_reviews إذا لم تكن النتيجة النهائية جاهزة بعد
    if (empty($scores) && Schema::hasTable('evaluation_reviews')) {
        $scores = DB::table('evaluation_reviews')
            ->join('evaluation_cycles', 'evaluation_reviews.evaluation_cycle_id', '=', 'evaluation_cycles.id')
            ->where('evaluation_reviews.company_id', $companyId)
            ->whereYear('evaluation_cycles.created_at', $year)
            ->whereNotNull('evaluation_reviews.total_score')
            ->selectRaw("
                CASE
                    WHEN evaluation_reviews.total_score >= 90 THEN 'excellent'
                    WHEN evaluation_reviews.total_score BETWEEN 75 AND 89.99 THEN 'good'
                    WHEN evaluation_reviews.total_score BETWEEN 60 AND 74.99 THEN 'acceptable'
                    ELSE 'weak'
                END as performance_level,
                COUNT(*) as count
            ")
            ->groupBy('performance_level')
            ->pluck('count', 'performance_level')
            ->toArray();
    }

    // 3. الخيار الثالث (Fallback): القراءة من إجابات الأسئلة وحقل hr_score أو rating
    if (empty($scores) && Schema::hasTable('evaluation_answers') && Schema::hasTable('evaluation_reviews')) {
        $scores = DB::table('evaluation_answers')
            ->join('evaluation_reviews', 'evaluation_answers.evaluation_review_id', '=', 'evaluation_reviews.id')
            ->join('evaluation_cycles', 'evaluation_reviews.evaluation_cycle_id', '=', 'evaluation_cycles.id')
            ->where('evaluation_reviews.company_id', $companyId)
            ->whereYear('evaluation_cycles.created_at', $year)
            ->selectRaw("
                CASE
                    WHEN COALESCE(evaluation_answers.hr_score, evaluation_answers.rating, 0) >= 90 THEN 'excellent'
                    WHEN COALESCE(evaluation_answers.hr_score, evaluation_answers.rating, 0) BETWEEN 75 AND 89 THEN 'good'
                    WHEN COALESCE(evaluation_answers.hr_score, evaluation_answers.rating, 0) BETWEEN 60 AND 74 THEN 'acceptable'
                    ELSE 'weak'
                END as performance_level,
                COUNT(*) as count
            ")
            ->groupBy('performance_level')
            ->pluck('count', 'performance_level')
            ->toArray();
    }

    $totalEvaluations = array_sum($scores);
    $getPercentage = fn($count) => $totalEvaluations > 0 ? round(($count / $totalEvaluations) * 100, 2) : 0;

    return [
        'year' => $year,
        'total_evaluations' => $totalEvaluations,
        'distribution' => [
            'excellent' => [
                'count' => $scores['excellent'] ?? 0,
                'percentage' => $getPercentage($scores['excellent'] ?? 0),
            ],
            'good' => [
                'count' => $scores['good'] ?? 0,
                'percentage' => $getPercentage($scores['good'] ?? 0),
            ],
            'acceptable' => [
                'count' => $scores['acceptable'] ?? 0,
                'percentage' => $getPercentage($scores['acceptable'] ?? 0),
            ],
            'weak' => [
                'count' => $scores['weak'] ?? 0,
                'percentage' => $getPercentage($scores['weak'] ?? 0),
            ],
        ]
    ];
}


}
