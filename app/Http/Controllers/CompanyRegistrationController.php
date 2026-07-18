<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use App\Http\Requests\CompanyRegistrationRequest;
use Illuminate\Http\JsonResponse;

class CompanyRegistrationController extends Controller
{
    /**
     * @OA\Post(
     *   path="/api/companies/register",
     *   summary="Register a new company and create its initial subscription",
     *   tags={"Companies"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","email","address","contact_name","phone","plan_id"},
     *       @OA\Property(property="name", type="string", example="Acme Company"),
     *       @OA\Property(property="email", type="string", format="email", example="hr@acme.com"),
     *       @OA\Property(property="domain", type="string", nullable=true, example="acme.example.com"),
     *       @OA\Property(property="address", type="string", example="Damascus"),
     *       @OA\Property(property="contact_name", type="string", example="Ahmad"),
     *       @OA\Property(property="phone", type="string", example="+963999999999"),
     *       @OA\Property(property="plan_id", type="string", format="uuid", example="00000000-0000-0000-0000-000000000000"),
     *     )
     *   ),
     *   @OA\Response(response=201, description="Company registered successfully")
     * )
     */
    public function __construct(private readonly SubscriptionService $subscriptionService)
    {
    }

    public function register(CompanyRegistrationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->subscriptionService->registerCompany($data);

        if (! empty($result['payment_required'])) {
            return response()->json([
                'success' => true,
                'payment_required' => true,
                'payment_url' => $result['payment_url'],
                'transaction_reference' => $result['transaction_reference'],
                'company_id' => $result['company_id'],
                'subscription_id' => $result['subscription_id'],
            ], 202);
        }

        return response()->json([
            'success' => true,
            'message' => 'Company registered successfully. Credentials were sent to the registered email.',
            'data' => [
                'company_id' => $result['company']->id,
            ],
        ], 201);
    }
}
