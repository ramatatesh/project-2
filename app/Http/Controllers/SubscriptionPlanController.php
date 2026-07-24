<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="Subscription Plans",
 *   description="API endpoints for managing subscription plans"
 * )
 */
class SubscriptionPlanController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/subscription-plans",
     *   summary="List all subscription plans",
     *   tags={"Subscription Plans"},
     *   @OA\Response(
     *     response=200,
     *     description="Successful operation",
     *     @OA\JsonContent(type="object")
     *   )
     * )
     */
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
        ->orderBy('price')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

/**
 * @OA\Get(
 *   path="/api/subscription-plans/all",
 *   summary="List all subscription plans including frozen plans (Super Admin only)",
 *   tags={"Subscription Plans"},
 *   security={{"sanctum":{}}},
 *
 *   @OA\Response(
 *      response=200,
 *      description="All subscription plans",
 *      @OA\JsonContent(type="object")
 *   ),
 *
 *   @OA\Response(
 *      response=401,
 *      description="Unauthenticated"
 *   ),
 *
 *   @OA\Response(
 *      response=403,
 *      description="Forbidden - Super Admin only"
 *   )
 * )
 */
    public function adminIndex(): JsonResponse
    {
      $plans = SubscriptionPlan::orderBy('price')->get();

         return response()->json([
          'success' => true,
          'data' => $plans,
         ]);
    }

/**
 * @OA\Post(
 *   path="/api/subscription-plans",
 *   summary="Create a new subscription plan",
 *   tags={"Subscription Plans"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"name","plan_type","billing_period","max_employees","price","max_uses_per_company"},
 *       @OA\Property(property="name", type="string", example="Standard"),
 *       @OA\Property(property="plan_type", type="string", example="paid"),
 *       @OA\Property(property="billing_period", type="string", example="month"),
 *       @OA\Property(property="max_employees", type="integer", example=100),
 *       @OA\Property(property="price", type="number", format="float", example=49.99),
 *       @OA\Property(property="is_active", type="boolean", example=true),
 *       @OA\Property(property="max_uses_per_company", type="integer", example=1),
 *       @OA\Property(property="description", type="string", example="Premium subscription plan")
 *     )
 *   ),
 *   @OA\Response(response=201, description="Plan created successfully")
 * )
 */
    public function store(SubscriptionPlanRequest $request): JsonResponse
    {
        $plan = SubscriptionPlan::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan created successfully.',
            'data' => $plan,
        ], 201);
    }

    /**
     * @OA\Get(
     *   path="/api/subscription-plans/{plan}",
     *   summary="Retrieve a subscription plan",
     *   tags={"Subscription Plans"},
     *   @OA\Parameter(
     *     name="plan",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Successful operation")
     * )
     */
    public function show(SubscriptionPlan $plan): JsonResponse
{
    if (!$plan->is_active) {
        return response()->json([
            'success' => false,
            'message' => 'Subscription plan not available'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'data' => $plan,
    ]);
}

    /**
     * @OA\Put(
     *   path="/api/subscription-plans/{plan}",
     *   summary="Update a subscription plan",
     *   tags={"Subscription Plans"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="plan",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","plan_type","billing_period","max_employees","price","max_uses_per_company"},
     *       @OA\Property(property="name", type="string", example="Standard"),
     *       @OA\Property(property="plan_type", type="string", example="paid"),
     *       @OA\Property(property="billing_period", type="string", example="month"),
     *       @OA\Property(property="max_employees", type="integer", example=100),
     *       @OA\Property(property="price", type="number", format="float", example=49.99),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *       @OA\Property(property="max_uses_per_company", type="integer", example=1),
     *       @OA\Property(property="description", type="string", example="Premium subscription plan")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Plan updated successfully")
     * )
     */
    public function update(SubscriptionPlanRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        $plan->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan updated successfully.',
            'data' => $plan,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/subscription-plans/{plan}/freeze",
     *   summary="Freeze a subscription plan",
     *   tags={"Subscription Plans"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="plan",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Plan frozen successfully")
     * )
     */
    public function freeze(SubscriptionPlan $plan): JsonResponse
    {
        $plan->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan frozen successfully.',
            'data' => $plan,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/subscription-plans/{plan}/activate",
     *   summary="Activate a subscription plan",
     *   tags={"Subscription Plans"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="plan",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Plan activated successfully")
     * )
     */
    public function activate(SubscriptionPlan $plan): JsonResponse
    {
        $plan->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan activated successfully.',
            'data' => $plan,
        ]);
    }

    /**
     * @OA\Delete(
     *   path="/api/subscription-plans/{plan}",
     *   summary="Delete a subscription plan",
     *   tags={"Subscription Plans"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="plan",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Plan deleted successfully")
     * )
     */
    public function destroy(SubscriptionPlan $plan): JsonResponse
    {
        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan deleted successfully.',
        ]);
    }
}
