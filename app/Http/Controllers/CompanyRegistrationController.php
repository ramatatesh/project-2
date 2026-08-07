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
     *   description="For the Free plan, the company/subscription/user are created immediately and credentials are emailed. A given email can only ever use the Free plan once - even after the company is later deleted. For a Paid plan, NOTHING is created yet: a Stripe Checkout Session is created and its URL is returned in 'payment_url'. The Company/Subscription/User are only created by the Stripe webhook (POST /api/stripe/webhook) after the payment actually succeeds; if payment fails or is abandoned, no data is created.",
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
     *       @OA\Property(property="phone", type="string", pattern="^09[0-9]{8}$", example="0999999999", description="يجب أن يبدأ بـ 09 ويتكون من 10 أرقام"),
     *       @OA\Property(property="plan_id", type="string", format="uuid", example="00000000-0000-0000-0000-000000000000"),
     *       @OA\Property(property="gender", type="string", enum={"male","female"}, nullable=true),
     *       @OA\Property(property="marital_status", type="string", enum={"single","married","divorced","widowed"}, nullable=true),
     *       @OA\Property(property="nationality", type="string", nullable=true, example="Syrian"),
     *       @OA\Property(property="residence", type="string", nullable=true, example="Damascus, Syria"),
     *     )
     *   ),
     *   @OA\Response(response=201, description="Free plan: company registered successfully, credentials emailed"),
     *   @OA\Response(
     *     response=202,
     *     description="Paid plan: redirect the user to 'payment_url' (Stripe Checkout). Nothing is created yet.",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="payment_required", type="boolean", example=true),
     *       @OA\Property(property="payment_url", type="string", example="https://checkout.stripe.com/c/pay/cs_test_..."),
     *       @OA\Property(property="transaction_reference", type="string", example="cs_test_a1b2c3")
     *     )
     *   ),
     *   @OA\Response(response=422, description="Validation failed (including duplicate email - companies.email is unique), or this email already used the Free plan before")
     * )
     */
    public function __construct(private readonly SubscriptionService $subscriptionService)
    {
    }

    public function register(CompanyRegistrationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->subscriptionService->registerCompany($data);

        if (empty($result['success'])) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Registration failed.',
            ], 422);
        }

        if (! empty($result['payment_required'])) {
            return response()->json([
                'success' => true,
                'payment_required' => true,
                'payment_url' => $result['payment_url'],
                'transaction_reference' => $result['transaction_reference'],
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
