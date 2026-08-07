<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PayrollAnalyticsService
{
    /**
     * Get comprehensive financial analytics for a specific company.
     *
     * @param string $companyId
     * @param int|null $year
     * @param int|null $month
     * @return array
     */
    public function getCompanyPayrollAnalytics(string $companyId, ?int $year = null, ?int $month = null): array
    {
        $year = $year ?? Carbon::now()->year;
        $month = $month ?? Carbon::now()->month;

        // 1. كتلة الرواتب الإجمالية للشهر الحالي (Total Payroll for Current Month)
        // باستخدام حقول جدول salary_records المعتمدة في مشروعك
        $currentMonthPayroll = DB::table('salary_records')
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->selectRaw('
                COALESCE(SUM(net_salary), 0) as total_net_payroll,
                COALESCE(SUM(base_salary), 0) as total_base_salary,
                COALESCE(SUM(overtime_amount + bonus_amount + manual_bonus), 0) as total_allowances,
                COALESCE(SUM(late_deduction + absent_deduction + loan_deduction + manual_deduction), 0) as total_deductions
            ')
            ->first();

        // 2. مجموع الوفورات المادية الإجمالية (Total Savings/Deductions)
        // المبالغ المقتطعة الناتجة عن حسميات التأخير والغياب
        $totalSavings = DB::table('salary_records')
            ->where('company_id', $companyId)
            ->selectRaw('
                COALESCE(SUM(late_deduction + absent_deduction), 0) as total_savings
            ')
            ->value('total_savings');

        // 3. مخطط المقارنة الشهرية لسنة كاملة (Monthly Payroll Cost Growth/Trend Chart)
        $monthlyTrends = DB::table('salary_records')
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->selectRaw('
                month as month_number,
                COALESCE(SUM(net_salary), 0) as monthly_net_payroll
            ')
            ->groupBy('month')
            ->orderBy('month_number', 'asc')
            ->pluck('monthly_net_payroll', 'month_number')
            ->toArray();

        // تجهيز مصفوفة لـ 12 شهراً لضمان عمل الرسوم البيانية (Charts)
        $formattedChartData = [];
        for ($m = 1; $m <= 12; $m++) {
            $formattedChartData[] = [
                'month' => $m,
                'month_name' => Carbon::create()->month($m)->format('F'),
                'total_cost' => (float) ($monthlyTrends[$m] ?? 0),
            ];
        }

        return [
            'current_month_summary' => [
                'year' => $year,
                'month' => $month,
                'total_net_payroll' => (float) ($currentMonthPayroll->total_net_payroll ?? 0),
                'total_base_salary' => (float) ($currentMonthPayroll->total_base_salary ?? 0),
                'total_allowances'  => (float) ($currentMonthPayroll->total_allowances ?? 0),
                'total_deductions'  => (float) ($currentMonthPayroll->total_deductions ?? 0),
            ],
            'total_savings' => (float) ($totalSavings ?? 0),
            'monthly_cost_trend' => $formattedChartData,
        ];
    }
}
