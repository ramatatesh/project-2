<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeService
{
    protected StripeClient $client;

    public function __construct()
    {
        $this->client = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Create a Stripe Checkout Session (one-time payment) for a new company registration.
     * All registration data is carried in the session metadata so the Company/Subscription
     * can be provisioned from the webhook, only after payment succeeds.
     *
     * This backend is API-only: success_url/cancel_url are never Laravel pages, they must be
     * the URLs of the separate frontend application, configured via STRIPE_CHECKOUT_SUCCESS_URL
     * and STRIPE_CHECKOUT_CANCEL_URL.
     *
     * @throws \RuntimeException when the frontend redirect URLs are not configured
     */
    public function createRegistrationCheckoutSession(SubscriptionPlan $plan, array $registrationData): Session
    {
        $successUrl = config('services.stripe.checkout_success_url');
        $cancelUrl = config('services.stripe.checkout_cancel_url');

        if (blank($successUrl) || blank($cancelUrl)) {
            throw new \RuntimeException(
                'STRIPE_CHECKOUT_SUCCESS_URL and STRIPE_CHECKOUT_CANCEL_URL must be set to the frontend application URLs before Stripe Checkout can be used.'
            );
        }

        if (! str_contains($successUrl, '{CHECKOUT_SESSION_ID}')) {
            $successUrl .= (str_contains($successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
        }

        $productData = [
            'name' => 'Khibrat HR - '.$plan->name.' Subscription',
        ];

        // Stripe rejects an empty string for this field, so it must be omitted entirely when unset.
        if (filled($plan->description)) {
            $productData['description'] = (string) $plan->description;
        }

        return $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'customer_email' => $registrationData['email'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('services.stripe.currency', 'usd'),
                    'unit_amount' => (int) round(((float) $plan->price) * 100),
                    'product_data' => $productData,
                ],
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'plan_id' => $plan->id,
                'name' => (string) $registrationData['name'],
                'email' => (string) $registrationData['email'],
                'domain' => (string) ($registrationData['domain'] ?? ''),
                'address' => (string) $registrationData['address'],
                'contact_name' => (string) ($registrationData['contact_name'] ?? $registrationData['name']),
                'phone' => (string) $registrationData['phone'],
                'payroll_currency' => (string) ($registrationData['payroll_currency'] ?? 'SYP'),
            ],
        ]);
    }

    /**
     * Verify and decode a raw Stripe webhook payload using the real Stripe-Signature header.
     *
     * @throws \UnexpectedValueException|\Stripe\Exception\SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): Event
    {
        return Webhook::constructEvent($payload, $signatureHeader, (string) config('services.stripe.webhook_secret'));
    }

    /**
     * Fetch a Checkout Session by id, so the frontend can poll payment status via a JSON API
     * after redirecting the user back from Stripe (used by StripeCheckoutController::status()).
     *
     * @throws \Stripe\Exception\ApiErrorException when the session id does not exist
     */
    public function retrieveCheckoutSession(string $sessionId): Session
    {
        return $this->client->checkout->sessions->retrieve($sessionId);
    }
}
