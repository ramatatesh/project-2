<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\PaymentTransaction;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use App\Models\Subscription;
use Carbon\Carbon;
/**
 * @OA\Tag(
 *   name="Companies",
 *   description="API endpoints for managing tenant companies and subscriptions"
 * )
 */
class CompanyAdminController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptionService)
    {
    }

    /**
     * @OA\Get(
     *   path="/api/companies",
     *   summary="List all tenant companies",
     *   tags={"Companies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Successful operation")
     * )
     */
    public function index(): JsonResponse
    {
        $companies = Company::with(['subscriptions.plan'])->get()->map(function (Company $company) {
            $currentSubscription = $company->subscriptions->sortByDesc('end_date')->first();

            return [
                'id' => $company->id,
                'name' => $company->name,
                'email' => $company->email,
                'domain' => $company->domain,
                'plan' => $currentSubscription?->plan?->name,
                'plan_end_date' => optional($currentSubscription?->end_date)->toDateString(),
                'is_active' => $company->status === 'active',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $companies,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/companies/{company}",
     *   summary="Retrieve company details with subscription info",
     *   tags={"Companies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Successful operation")
     * )
     */
    public function show(Company $company): JsonResponse
    {
        $company->load(['subscriptions.plan', 'users']);

        $currentSubscription = $company->subscriptions->sortByDesc('end_date')->first();
        $manager = $company->users->firstWhere('role', Role::GeneralManager->value);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $company->id,
                'name' => $company->name,
                'email' => $company->email,
                'domain' => $company->domain,
                'manager_full_name' => $manager?->full_name,
                'phone' => $company->phone,
                'address' => $company->address,
                'plan_price' => $currentSubscription?->monthly_price,
                'start_date' => optional($currentSubscription?->start_date)->toDateString(),
                'end_date' => optional($currentSubscription?->end_date)->toDateString(),
                'status' => $company->status,
            ],
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/freeze",
     *   summary="Freeze a company's account",
     *   tags={"Companies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Company frozen successfully")
     * )
     */
    public function freeze(Company $company): JsonResponse
    {
        $this->subscriptionService->suspendCompany($company);

        return response()->json([
            'success' => true,
            'message' => 'Company has been frozen successfully.',
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/activate",
     *   summary="Reactivate a company's account",
     *   tags={"Companies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Company reactivated successfully")
     * )
     */
    public function activate(Company $company): JsonResponse
    {
        $this->subscriptionService->activateCompany($company);

        return response()->json([
            'success' => true,
            'message' => 'Company has been reactivated successfully.',
        ]);
    }

    /**
     * @OA\Delete(
     *   path="/api/companies/{company}",
     *   summary="Delete a company permanently",
     *   tags={"Companies"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Company deleted successfully")
     * )
     */
    public function destroy(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json([
            'success' => true,
            'message' => 'Company has been deleted permanently.',
        ]);
    }
/**
     * @OA\Get(
     * path="/api/companies/stats",
     * summary="جلب الإحصائيات الشاملة والمالية للوحة التحكم (Dashboard Stats)",
     * description="يعيد هذا الـ API كافة الأرقام المالية، توزيع حالات الشركات للمخطط الدائري، البيانات الزمنية لآخر 6 أشهر للمخطط الخطي، وقائمة بآخر المنصات المسجلة لتغذية الـ Dashboard بالكامل.",
     * tags={"الشركات والاشتراكات (Companies & Subscriptions)"},
     * security={{"sanctum":{}}},
     * @OA\Response(
     * response=200,
     * description="تم جلب بيانات لوحة التحكم بنجاح",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="data", type="object",
     * @OA\Property(property="summary", type="object",
     * @OA\Property(property="total_revenue", type="number", format="float", example=2878.00),
     * @OA\Property(property="monthly_revenue", type="number", format="float", example=49.99),
     * @OA\Property(property="yearly_revenue", type="number", format="float", example=1200.50),
     * @OA\Property(property="total_subscriptions", type="integer", example=5),
     * @OA\Property(property="new_companies_this_month", type="integer", example=3),
     * @OA\Property(property="auto_deletion_period_days", type="integer", example=30)
     * ),
     * @OA\Property(property="status_distribution", type="object",
     * @OA\Property(property="active", type="integer", example=4),
     * @OA\Property(property="frozen", type="integer", example=1),
     * @OA\Property(property="at_risk", type="integer", example=0)
     * ),
     * @OA\Property(property="monthly_subscription_analytics", type="array",
     * @OA\Items(
     * @OA\Property(property="month", type="string", example="Jun 2026"),
     * @OA\Property(property="count", type="integer", example=5)
     * )
     * ),
     * @OA\Property(property="latest_registered_platforms", type="array",
     * @OA\Items(
     * @OA\Property(property="id", type="string", format="uuid", example="74523827-0f69-45ce-be73-afd7be29f6e4"),
     * @OA\Property(property="name", type="string", example="شركة النور التقنية"),
     * @OA\Property(property="created_at", type="string", format="date", example="2026-07-03"),
     * @OA\Property(property="status", type="string", example="active"),
     * @OA\Property(property="package", type="string", example="paid")
     * )
     * )
     * )
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="خطأ داخلي في السيرفر أثناء احتساب الإحصائيات"
     * )
     * )
     */
  public function stats(): JsonResponse
{
    // 1. الإحصائيات العامة (Summary Metrics)
    $totalCompanies = Company::count();
    $totalSubscriptions = Subscription::count();
    $newCompaniesThisMonth = Company::where('name', '!=', 'Khibrat (Platform Owner)')
        ->whereMonth('created_at', now()->month)
        ->whereYear('created_at', now()->year)
        ->count();

    // الأسطر المطلوبة لحساب المجاني والمدفوع:
    $freeCompanies = Company::whereHas('subscriptions', fn ($query) => $query->where('plan_type', 'free'))->count();
    $paidCompanies = Company::whereHas('subscriptions', fn ($query) => $query->where('plan_type', 'paid'))->count();

    // حساب الإيرادات الإجمالية والشهرية
    $totalRevenue = PaymentTransaction::where('status', 'paid')->sum('amount');
    $monthlyRevenue = PaymentTransaction::where('status', 'paid')->whereMonth('created_at', now()->month)->sum('amount');
    $yearlyRevenue = PaymentTransaction::where('status', 'paid')->whereYear('created_at', now()->year)->sum('amount');

    // 2. توزيع حالات الشركات للمخطط الدائري (Company Status Distribution)
    $activeCompanies = Company::where('status', 'active')->orWhere('status', 'ACTIVE')->count();
    $frozenCompanies = Company::where('status', 'suspended')->count();

    $atRiskCompanies = Company::whereHas('subscriptions', function ($query) {
        $query->where('status', 'expired')
              ->orWhere(function ($q) {
                  $q->where('status', 'active')
                    ->where('end_date', '<=', now()->addDays(5));
              });
    })->count();

    // 3. تجهيز بيانات المخطط البياني لآخر 6 أشهر
    $monthlyAnalytics = [];
    for ($i = 5; $i >= 0; $i--) {
        $monthDate = now()->startOfMonth()->subMonths($i);
        $monthName = $monthDate->format('M Y');

        $count = Subscription::whereMonth('created_at', $monthDate->month)
            ->whereYear('created_at', $monthDate->year)
            ->count();

        $monthlyAnalytics[] = [
            'month' => $monthName,
            'count' => $count
        ];
    }

    // 4. جلب آخر 5 منصات/شركات تم تسجيلها
   $latestCompanies = Company::whereHas('subscriptions')
    ->with(['subscriptions' => function($q) {
        $q->latest();
    }])
    ->latest()
    ->take(5)
    ->get()
        ->map(function ($company) {
            return [
                'id' => $company->id,
                'name' => $company->name,
                'created_at' => $company->created_at ? \Carbon\Carbon::parse($company->created_at)->format('Y-m-d') : 'N/A',
                'status' => $company->status,
                'package' => $company->subscriptions->first()?->plan_type ?? 'N/A'
            ];
        });

    // 5. بناء الرد وتضمين المتغيرات الجديدة داخل summary
    return response()->json([
        'success' => true,
        'data' => [
            'summary' => [
                'total_companies' => $totalCompanies, // إجمالي الشركات الكلي
                'free_companies' => $freeCompanies,   // عدد الشركات المجانية
                'paid_companies' => $paidCompanies,   // عدد الشركات المدفوعة
                'total_revenue' => (float) $totalRevenue,
                'monthly_revenue' => (float) $monthlyRevenue,
                'yearly_revenue' => (float) $yearlyRevenue,
                'total_subscriptions' => $totalSubscriptions,
                'new_companies_this_month' => $newCompaniesThisMonth,
                'auto_deletion_period_days' => 30
            ],
            'status_distribution' => [
                'active' => $activeCompanies,
                'frozen' => $frozenCompanies,
                'at_risk' => $atRiskCompanies
            ],
            'monthly_subscription_analytics' => $monthlyAnalytics,
            'latest_registered_platforms' => $latestCompanies
        ]
    ]);
}

}
