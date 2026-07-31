<?php

namespace App\Http\Controllers;

use App\Services\StripeService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * @OA\Post(
     *   path="/api/stripe/webhook",
     *   summary="Stripe webhook endpoint (Checkout Session events)",
     *   description="Official Stripe webhook. On 'checkout.session.completed' with a successful payment, this endpoint provisions the Company/Subscription/User for a paid registration - this is the ONLY place where a paid company is created. Verified using the real Stripe-Signature header (STRIPE_WEBHOOK_SECRET), not a shared secret.",
     *   tags={"بوابات الدفع والاشتراكات (Payments & Subscriptions)"},
     *   @OA\Parameter(
     *     name="Stripe-Signature",
     *     in="header",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     description="Raw Stripe Event payload (sent by Stripe servers)",
     *     @OA\JsonContent(type="object")
     *   ),
     *   @OA\Response(response=200, description="Event processed (or acknowledged/ignored)"),
     *   @OA\Response(response=400, description="Invalid payload or signature")
     * )
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $signature);
        } catch (UnexpectedValueException $e) {
            Log::warning('Stripe webhook: invalid payload.', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Invalid payload.'], 400);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook: invalid signature.', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            /** @var \Stripe\Checkout\Session $session */
            $session = $event->data->object;

            if ($session->payment_status === 'paid') {
                $result = $this->subscriptionService->activateCompanyFromStripeSession($session);

                // Always acknowledge with 200 once the signature is verified, so Stripe does not
                // keep retrying a permanently-failing event (e.g. missing plan). Failures are logged
                // for manual follow-up instead of surfacing as webhook delivery errors.
                return response()->json($result, 200);
            }

            Log::info('Stripe webhook: checkout session completed but not paid yet.', ['session_id' => $session->id]);
        }

        return response()->json(['success' => true, 'message' => 'Event received.'], 200);
    }
}
