<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanySubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptionService)
    {
    }

    /**
     * @OA\Get(
     *   path="/api/company/subscription",
     *   summary="Get the current company's subscription status",
     *   tags={"Companies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Current subscription")
     * )
     */
    public function show(): JsonResponse
    {
        $company = auth()->user()->company()->with(['subscriptions.plan'])->first();

        if (! $company) {
            return response()->json(['success' => false, 'message' => 'Company not found.'], 404);
        }

        $current = $company->subscriptions->sortByDesc(function ($subscription) {
            return optional($subscription->end_date)?->timestamp ?? 0;
        })->first();

        return response()->json([
            'success' => true,
            'data' => [
                'company_id' => $company->id,
                'company_status' => $company->status,
                'subscription' => $current ? [
                    'id' => $current->id,
                    'status' => $current->status,
                    'plan_id' => $current->plan_id,
                    'plan_name' => $current->plan?->name,
                    'plan_type' => $current->plan_type,
                    'billing_period' => $current->plan?->billing_period,
                    'start_date' => optional($current->start_date)?->toDateString(),
                    'end_date' => optional($current->end_date)?->toDateString(),
                ] : null,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/company/subscription/usage",
     *   summary="Package consumption: employees used and days/months remaining",
     *   tags={"Companies"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Subscription package usage"),
     *   @OA\Response(response=404, description="Company not found")
     * )
     */
    public function usage(): JsonResponse
    {
        $company = auth()->user()->company;

        if (! $company) {
            return response()->json(['success' => false, 'message' => 'Company not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->subscriptionService->getPackageUsage($company),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/company/subscription/renew",
     *   summary="Start a Stripe Checkout session to renew the current company's subscription",
     *   tags={"Companies"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"plan_id"},
     *       @OA\Property(property="plan_id", type="string", format="uuid")
     *     )
     *   ),
     *   @OA\Response(response=202, description="Redirect the user to payment_url")
     * )
     */
    public function renew(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => ['required', 'string', 'uuid', 'exists:subscription_plans,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $company = auth()->user()->company;

        if (! $company) {
            return response()->json(['success' => false, 'message' => 'Company not found.'], 404);
        }

        try {
            $result = $this->subscriptionService->startRenewalCheckout($company, $validator->validated()['plan_id']);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        if (empty($result['success'])) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Unable to start renewal.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'payment_required' => true,
            'payment_url' => $result['payment_url'],
            'transaction_reference' => $result['transaction_reference'],
        ], 202);
    }
}
