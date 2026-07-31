<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Company;
use App\Models\FreePlanUsage;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function __construct(private readonly StripeService $stripeService)
    {
    }

    public function registerCompany(array $data): array
    {
        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        // في حال كانت الباقة مجانية (Free Plan)
        if (strtolower($plan->plan_type) === 'free') {
            $email = strtolower($data['email']);

            // كل شركة (بحسب البريد الإلكتروني) يحق لها استخدام الخطة المجانية مرة واحدة فقط طوال حياتها،
            // حتى لو حُذفت الشركة أو انتهى اشتراكها لاحقاً - لذلك يتم التحقق من سجل دائم منفصل لا يُحذف أبداً.
            if (FreePlanUsage::where('email', $email)->exists()) {
                return [
                    'success' => false,
                    'message' => 'This email has already used the free plan before. The free plan can only be used once per company.',
                ];
            }

            $company = Company::create([
                'id' => Str::uuid()->toString(),
                'name' => $data['name'],
                'email' => $data['email'],
                'domain' => $data['domain'] ?? null,
                'address' => $data['address'],
                'phone' => $data['phone'],
                'status' => 'active',
                'payroll_currency' => $data['payroll_currency'] ?? 'SYP',
            ]);

            $subscription = Subscription::create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'plan_type' => $plan->plan_type,
                'monthly_price' => $plan->price,
                'start_date' => now()->startOfDay(),
                'end_date' => now()->addMonth()->startOfDay(),
                'status' => 'active', // أحرف صغيرة
            ]);

            $tempPassword = Str::random(10);
            $user = User::create([
                'company_id' => $company->id,
                'full_name' => $data['contact_name'] ?? $data['name'],
                'email' => $data['email'],
                'password_hash' => bcrypt($tempPassword),
                'role' => Role::GeneralManager->value,
                'status' => 'active', // أحرف صغيرة ليمر الـ Login بنجاح
                'is_first_login' => true,
            ]);

            Department::create([
              'id' => Str::uuid()->toString(),
              'company_id' => $company->id,
              'name' => 'Human Resources',
              'is_active' => true,
              'manager_id' => null,
            ]);

            FreePlanUsage::create([
                'id' => Str::uuid()->toString(),
                'email' => $email,
                'domain' => $data['domain'] ?? null,
            ]);

            dispatch(new \App\Jobs\SendRegistrationEmailJob($company->email, $tempPassword));

            return [
                'success' => true,
                'company' => $company,
                'subscription' => $subscription,
                'user' => $user,
            ];
        }

        // في حال كانت الباقة مدفوعة (Paid Plan): لا يتم إنشاء أي بيانات في النظام هنا إطلاقاً.
        // يتم فقط إنشاء Stripe Checkout Session، وإنشاء الشركة/الاشتراك يحدث لاحقاً داخل الـ Webhook بعد نجاح الدفع فعلياً.
        $session = $this->stripeService->createRegistrationCheckoutSession($plan, $data);

        return [
            'success' => true,
            'payment_required' => true,
            'payment_url' => $session->url,
            'transaction_reference' => $session->id,
        ];
    }

    protected function createPaymentTransaction(string $companyId, string $subscriptionId, $amount, string $reference)
    {
        return \App\Models\PaymentTransaction::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $companyId,
            'subscription_id' => $subscriptionId,
            'amount' => $amount,
            'gateway' => 'SimulatedGateway',
            'transaction_reference' => $reference,
            'status' => 'pending', // أحرف صغيرة لتتوافق مع الـ Request Validation للدفع
        ]);
    }

    public function finalizePayment(string $transactionReference, bool $success): array
    {
        $transaction = \App\Models\PaymentTransaction::where('transaction_reference', $transactionReference)->first();

        if (! $transaction) {
            return ['success' => false, 'message' => 'Transaction not found.'];
        }

        $subscription = Subscription::find($transaction->subscription_id);
        $company = Company::find($transaction->company_id);

        if ($success) {
            $transaction->update(['status' => 'paid']); // أحرف صغيرة
            $subscription->update(['status' => 'active']); // أحرف صغيرة
            $company->update(['status' => 'active']); // أحرف صغيرة

            $tempPassword = Str::random(10);
            $user = User::create([
                'company_id' => $company->id,
                'full_name' => $company->name,
                'email' => $company->email,
                'password_hash' => bcrypt($tempPassword),
                'role' => Role::GeneralManager->value,
                'status' => 'active', // أحرف صغيرة لتفادي مشكلة الحساب غير النشط بعد الدفع
                'is_first_login' => true,
            ]);

            // إطلاق الـ Job لإرسال بيانات الدخول (Welcome) للمدير العام
            dispatch(new \App\Jobs\SendRegistrationEmailJob($user->email, $tempPassword));

            return ['success' => true, 'company' => $company, 'user' => $user];
        }

        $transaction->update(['status' => 'failed']); // أحرف صغيرة
        $subscription->update(['status' => 'cancelled']); // أحرف صغيرة

        return ['success' => false, 'message' => 'Payment failed.'];
    }

    /**
     * Provision a Company/Subscription/Department/User from a completed Stripe Checkout Session.
     * Called from the Stripe webhook after payment succeeds - never before.
     */
    public function activateCompanyFromStripeSession(\Stripe\Checkout\Session $session): array
    {
        // Idempotency: Stripe may redeliver the same event more than once.
        if (\App\Models\PaymentTransaction::where('stripe_checkout_session_id', $session->id)->exists()) {
            return ['success' => true, 'message' => 'Already processed.'];
        }

        $metadata = $session->metadata;
        $plan = SubscriptionPlan::find($metadata['plan_id'] ?? null);

        if (! $plan) {
            Log::error('Stripe webhook: subscription plan not found for checkout session.', ['session_id' => $session->id]);

            return ['success' => false, 'message' => 'Subscription plan not found for this checkout session.'];
        }

        try {
            return DB::transaction(function () use ($session, $metadata, $plan) {
                $company = Company::create([
                    'id' => Str::uuid()->toString(),
                    'name' => (string) $metadata['name'],
                    'email' => (string) $metadata['email'],
                    'domain' => filled($metadata['domain'] ?? null) ? $metadata['domain'] : null,
                    'address' => (string) $metadata['address'],
                    'phone' => (string) $metadata['phone'],
                    'status' => 'active',
                    'payroll_currency' => filled($metadata['payroll_currency'] ?? null) ? $metadata['payroll_currency'] : 'SYP',
                ]);

                $subscription = Subscription::create([
                    'company_id' => $company->id,
                    'plan_id' => $plan->id,
                    'plan_type' => $plan->plan_type,
                    'monthly_price' => $plan->price,
                    'start_date' => now()->startOfDay(),
                    'end_date' => $this->calculateSubscriptionEndDate($plan->billing_period),
                    'status' => 'active',
                ]);

                Department::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $company->id,
                    'name' => 'Human Resources',
                    'is_active' => true,
                    'manager_id' => null,
                ]);

                $paymentIntentId = $session->payment_intent;
                $paymentIntentId = is_string($paymentIntentId) ? $paymentIntentId : ($paymentIntentId->id ?? null);

                \App\Models\PaymentTransaction::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'amount' => $session->amount_total !== null ? $session->amount_total / 100 : $plan->price,
                    'gateway' => 'stripe',
                    'transaction_reference' => $session->id,
                    'status' => 'paid',
                    'stripe_checkout_session_id' => $session->id,
                    'stripe_payment_intent_id' => $paymentIntentId,
                ]);

                $tempPassword = Str::random(10);
                $user = User::create([
                    'company_id' => $company->id,
                    'full_name' => filled($metadata['contact_name'] ?? null) ? $metadata['contact_name'] : $company->name,
                    'email' => $company->email,
                    'password_hash' => bcrypt($tempPassword),
                    'role' => Role::GeneralManager->value,
                    'status' => 'active',
                    'is_first_login' => true,
                ]);

                dispatch(new \App\Jobs\SendRegistrationEmailJob($user->email, $tempPassword));

                return ['success' => true, 'company' => $company, 'user' => $user];
            });
        } catch (\Throwable $e) {
            Log::error('Stripe webhook: failed to provision company after payment.', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Failed to provision company: '.$e->getMessage()];
        }
    }

    protected function calculateSubscriptionEndDate(string $billingPeriod): \Carbon\Carbon
    {
        return match ($billingPeriod) {
            'quarter' => now()->addMonths(3)->startOfDay(),
            'year' => now()->addYear()->startOfDay(),
            default => now()->addMonth()->startOfDay(),
        };
    }

    public function refreshCompanySubscriptionStatus(Company $company): void
    {
        $activeSubscription = $company->subscriptions()->where('status', 'active')->latest('end_date')->first();

        if ($activeSubscription && $activeSubscription->end_date && $activeSubscription->end_date->lt(now()->startOfDay())) {
            $activeSubscription->update(['status' => 'expired']); // أحرف صغيرة
            $company->update(['status' => 'suspended']); // أحرف صغيرة
            return;
        }

        if (! $activeSubscription) {
            $company->update(['status' => 'suspended']); // أحرف صغيرة
        }
    }


    public function suspendCompany(\App\Models\Company $company): void
    {

        $company->update(['status' => 'suspended']);

        $company->subscriptions()->where('status', 'active')->update([
            'status' => 'suspended'
        ]);
    }

    public function activateCompany(\App\Models\Company $company): void
    {
        $company->update(['status' => 'active']);


        $company->subscriptions()->where('status', 'suspended')->update([
            'status' => 'active'
        ]);
    }
}
