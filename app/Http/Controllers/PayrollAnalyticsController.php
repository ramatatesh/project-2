<?php

namespace App\Http\Controllers;

use App\Services\PayrollAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PayrollAnalyticsController extends Controller
{
    protected PayrollAnalyticsService $analyticsService;

    public function __construct(PayrollAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * @OA\Get(
     *     path="/api/payrolls/analytics",
     *     summary="Get Payroll Financial Analytics",
     *     description="Returns total current monthly payroll, accumulated savings, and 12-month cost comparison for charts.",
     *     tags={"Payroll Analytics"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         description="Year for analytics (default: current year)",
     *         @OA\Schema(type="integer", example=2026)
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         description="Month for current total (default: current month)",
     *         @OA\Schema(type="integer", example=8)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="current_month_summary", type="object",
     *                     @OA\Property(property="year", type="integer", example=2026),
     *                     @OA\Property(property="month", type="integer", example=8),
     *                     @OA\Property(property="total_net_payroll", type="number", format="float", example=12500.50),
     *                     @OA\Property(property="total_base_salary", type="number", format="float", example=11000.00),
     *                     @OA\Property(property="total_allowances", type="number", format="float", example=2000.00),
     *                     @OA\Property(property="total_deductions", type="number", format="float", example=499.50)
     *                 ),
     *                 @OA\Property(property="total_savings", type="number", format="float", example=1850.75),
     *                 @OA\Property(property="monthly_cost_trend", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="month", type="integer", example=1),
     *                         @OA\Property(property="month_name", type="string", example="January"),
     *                         @OA\Property(property="total_cost", type="number", format="float", example=12000.00)
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
   public function index(Request $request): JsonResponse
{
    $user = $request->user();

    if (!$user) {
        return response()->json([
            'message' => 'Access denied.'
        ], 401);
    }

    // فحص الدور بأمان
    // سنتحقق إما عن طريق علاقة role أو خاصية role الموجودة في نموذج المستخدم
    $userRole = strtolower($user->role->name ?? $user->role ?? '');

    $allowedRoles = ['hr_manager', 'general_manager'];

    if (!in_array($userRole, $allowedRoles)) {
        return response()->json([
            'message' => 'This action is only available to the company manager and HR manager.'
        ], 403);
    }

    $companyId = $user->company_id;

    $year = $request->query('year') ? (int) $request->query('year') : null;
    $month = $request->query('month') ? (int) $request->query('month') : null;

    $analytics = $this->analyticsService->getCompanyPayrollAnalytics($companyId, $year, $month);

    return response()->json([
        'success' => true,
        'data' => $analytics,
    ]);
}
}
