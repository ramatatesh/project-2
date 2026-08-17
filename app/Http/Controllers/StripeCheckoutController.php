<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Services\StripeService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

class StripeCheckoutController extends Controller
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/stripe/checkout-sessions/{session_id}",
     *   summary="Verify Stripe Checkout Session after frontend redirect",
     *   description="Call this from /payment/success?session_id=... and poll until status=completed before showing success / login. Do not treat a Stripe redirect alone as success. Values: completed (account ready, email sent), processing (paid, still provisioning), not_completed (payment not finished).",
     *   tags={"بوابات الدفع والاشتراكات (Payments & Subscriptions)"},
     *   @OA\Parameter(
     *     name="session_id",
     *     in="path",
     *     required=true,
     *     description="Stripe Checkout Session id from the success URL query (?session_id=) — same as transaction_reference from register/renew",
     *     @OA\Schema(type="string", example="cs_test_a1b2c3")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Session status",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="status", type="string", enum={"completed","processing","not_completed"}, example="completed"),
     *       @OA\Property(property="can_login", type="boolean", example=true),
     *       @OA\Property(property="message", type="string"),
     *       @OA\Property(property="company_id", type="string", format="uuid", nullable=true),
     *       @OA\Property(property="subscription_id", type="string", format="uuid", nullable=true)
     *     )
     *   ),
     *   @OA\Response(response=404, description="No such checkout session")
     * )
     */
    public function status(string $sessionId): JsonResponse
    {
        $sessionId = urldecode(trim($sessionId));

        if ($sessionId === '' || str_contains($sessionId, '{')) {
            return response()->json([
                'success' => false,
                'status' => 'not_completed',
                'can_login' => false,
                'message' => 'Missing or invalid session_id. Read it from the success URL query string (?session_id=cs_...).',
                'company_id' => null,
            ], 422);
        }

        $transaction = $this->findTransaction($sessionId);

        if ($transaction) {
            $purpose = $this->detectPurpose($sessionId);
            return $this->completedResponse($transaction, $purpose);
        }

        try {
            $session = $this->stripeService->retrieveCheckoutSession($sessionId);
        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'status' => 'not_completed',
                'can_login' => false,
                'message' => 'Checkout session not found.',
                'company_id' => null,
            ], 404);
        }

        $purpose = $session->metadata['purpose'] ?? 'registration';

        if (($session->payment_status ?? null) !== 'paid') {
            return response()->json([
                'success' => true,
                'status' => 'not_completed',
                'can_login' => false,
                'message' => 'Payment has not been completed yet.',
                'company_id' => null,
                'payment_status' => $session->payment_status,
                'purpose' => $purpose,
            ]);
        }

        // Paid at Stripe but webhook may be late — try to provision now (idempotent).
        try {
            if ($purpose === 'renewal') {
                $this->subscriptionService->renewCompanyFromStripeSession($session);
            } else {
                $this->subscriptionService->activateCompanyFromStripeSession($session);
            }
        } catch (\Throwable $e) {
            Log::warning('Checkout status: fallback provisioning failed.', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        $transaction = $this->findTransaction($sessionId);

        if ($transaction) {
            return $this->completedResponse($transaction, $purpose);
        }

        return response()->json([
            'success' => true,
            'status' => 'processing',
            'can_login' => false,
            'message' => 'Payment succeeded. Account provisioning is still in progress — poll again in 1–2 seconds.',
            'company_id' => null,
            'payment_status' => 'paid',
        ]);
    }

    private function detectPurpose(string $sessionId): string
    {
        try {
            $session = $this->stripeService->retrieveCheckoutSession($sessionId);
            return $session->metadata['purpose'] ?? 'registration';
        } catch (\Throwable) {
            return 'registration';
        }
    }

    private function findTransaction(string $sessionId): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('stripe_checkout_session_id', $sessionId)
            ->orWhere('transaction_reference', $sessionId)
            ->first();
    }

    private function completedResponse(PaymentTransaction $transaction, string $purpose = 'registration'): JsonResponse
    {
        $isRenewal = $purpose === 'renewal';

        return response()->json([
            'success' => true,
            'status' => 'completed',
            'can_login' => true,
            'message' => $isRenewal
                ? 'Payment successful. Your workspace has been reactivated — please set a new password to continue.'
                : 'Payment successful. Account is ready — check email for the temporary password, then go to login.',
            'company_id' => $transaction->company_id,
            'subscription_id' => $transaction->subscription_id,
            'purpose' => $purpose,
            'requires_password_change' => $isRenewal,
        ]);
    }
}
