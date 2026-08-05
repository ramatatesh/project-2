<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.payment.webhook_secret');

        if (blank($secret)) {
            Log::warning('Payment webhook rejected: PAYMENT_WEBHOOK_SECRET is not configured.', [
                'ip' => $request->ip(),
            ]);

            abort(500, 'Webhook secret is not configured.');
        }

        $signature = $request->header('X-Webhook-Signature');

        if (! hash_equals($secret, (string) $signature)) {
            Log::warning('Payment webhook rejected: invalid signature.', [
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
