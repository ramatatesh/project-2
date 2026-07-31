<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Stripe\Exception\ApiErrorException;

class StripeCheckoutController extends Controller
{
    public function __construct(private readonly StripeService $stripeService)
    {
    }

    /**
     * @OA\Get(
     *   path="/api/stripe/checkout-sessions/{session_id}",
     *   summary="Check the status of a Stripe Checkout Session (pure JSON, for the separate frontend)",
     *   description="The frontend calls this after redirecting the user back from Stripe Checkout (success_url/cancel_url are frontend routes, not backend pages) to find out whether the payment succeeded and whether the company/subscription has been provisioned yet by the webhook. Provisioning always happens asynchronously via POST /api/stripe/webhook, so right after redirect the status may briefly be 'processing' before becoming 'completed'.",
     *   tags={"بوابات الدفع والاشتراكات (Payments & Subscriptions)"},
     *   @OA\Parameter(
     *     name="session_id",
     *     in="path",
     *     required=true,
     *     description="The Stripe Checkout Session id (also returned as 'transaction_reference' by POST /api/companies/register)",
     *     @OA\Schema(type="string", example="cs_test_a1b2c3")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Session status",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="status", type="string", enum={"completed","processing","not_completed"}, example="completed"),
     *       @OA\Property(property="company_id", type="string", format="uuid", nullable=true)
     *     )
     *   ),
     *   @OA\Response(response=404, description="No such checkout session")
     * )
     */
    public function status(string $sessionId): JsonResponse
    {
        $transaction = PaymentTransaction::where('stripe_checkout_session_id', $sessionId)->first();

        if ($transaction) {
            return response()->json([
                'success' => true,
                'status' => 'completed',
                'company_id' => $transaction->company_id,
                'subscription_id' => $transaction->subscription_id,
            ]);
        }

        try {
            $session = $this->stripeService->retrieveCheckoutSession($sessionId);
        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Checkout session not found.',
            ], 404);
        }

        if ($session->payment_status === 'paid') {
            // Payment succeeded at Stripe, but the webhook has not provisioned the company yet.
            return response()->json([
                'success' => true,
                'status' => 'processing',
                'company_id' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => 'not_completed',
            'company_id' => null,
        ]);
    }
}
