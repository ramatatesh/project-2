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
                'gender' => $data['gender'] ?? null,
                'marital_status' => $data['marital_status'] ?? null,
                'nationality' => $data['nationality'] ?? null,
                'residence' => $data['residence'] ?? null,
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
                    'gender' => filled($metadata['gender'] ?? null) ? $metadata['gender'] : null,
                    'marital_status' => filled($metadata['marital_status'] ?? null) ? $metadata['marital_status'] : null,
                    'nationality' => filled($metadata['nationality'] ?? null) ? $metadata['nationality'] : null,
                    'residence' => filled($metadata['residence'] ?? null) ? $metadata['residence'] : null,
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
        return $this->calculateSubscriptionEndDateFrom(now()->startOfDay(), $billingPeriod);
    }

    public function calculateSubscriptionEndDateFrom(\Carbon\CarbonInterface $from, ?string $billingPeriod): \Carbon\Carbon
    {
        $from = \Carbon\Carbon::parse($from)->startOfDay();

        return match ($billingPeriod) {
            'quarter' => $from->copy()->addMonths(3),
            'year' => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }

    public function expireOverdueSubscriptions(): int
    {
        $expiredCount = 0;

        Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (&$expiredCount) {
                $companyIds = [];

                foreach ($subscriptions as $subscription) {
                    $subscription->update(['status' => 'expired']);
                    $expiredCount++;
                    $companyIds[] = $subscription->company_id;
                }

                foreach (array_unique($companyIds) as $companyId) {
                    $company = Company::find($companyId);
                    if ($company) {
                        $this->refreshCompanySubscriptionStatus($company);
                    }
                }
            });

        return $expiredCount;
    }

    public function refreshCompanySubscriptionStatus(Company $company): void
    {
        $company->subscriptions()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString())
            ->update(['status' => 'expired']);

        $hasValidSubscription = $company->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->exists();

        if ($hasValidSubscription) {
            return;
        }

        if ($company->subscriptions()->exists()) {
            $company->update(['status' => 'suspended']);
        }
    }

    public function startRenewalCheckout(Company $company, string $planId): array
    {
        $plan = SubscriptionPlan::find($planId);

        if (! $plan || ! $plan->is_active) {
            return ['success' => false, 'message' => 'The selected subscription plan is not available.'];
        }

        if ((float) $plan->price <= 0) {
            return ['success' => false, 'message' => 'Renewal requires a paid subscription plan.'];
        }

        $session = $this->stripeService->createRenewalCheckoutSession($company, $plan);

        return [
            'success' => true,
            'payment_required' => true,
            'payment_url' => $session->url,
            'transaction_reference' => $session->id,
        ];
    }

    /**
     * Apply a paid renewal to an existing company. Never creates a new company/users/employees.
     */
    public function renewCompanyFromStripeSession(\Stripe\Checkout\Session $session): array
    {
        if (\App\Models\PaymentTransaction::where('stripe_checkout_session_id', $session->id)->exists()) {
            return ['success' => true, 'message' => 'Already processed.'];
        }

        $metadata = $session->metadata;
        $company = Company::find($metadata['company_id'] ?? null);
        $plan = SubscriptionPlan::find($metadata['plan_id'] ?? null);

        if (! $company || ! $plan) {
            Log::error('Stripe webhook: renewal company or plan not found.', ['session_id' => $session->id]);

            return ['success' => false, 'message' => 'Company or subscription plan not found for this renewal.'];
        }

        try {
            return DB::transaction(function () use ($session, $company, $plan) {
                $subscription = $this->applyPaidRenewal($company, $plan);

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

                return ['success' => true, 'company' => $company->fresh(), 'subscription' => $subscription];
            });
        } catch (\Throwable $e) {
            Log::error('Stripe webhook: failed to renew company subscription.', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Failed to renew subscription: '.$e->getMessage()];
        }
    }

    public function applyPaidRenewal(Company $company, SubscriptionPlan $plan): Subscription
    {
        $extendable = $company->subscriptions()
            ->whereIn('status', ['active', 'suspended'])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', now()->toDateString())
            ->orderByDesc('end_date')
            ->first();

        if ($extendable) {
            $extendable->update([
                'plan_id' => $plan->id,
                'plan_type' => $plan->plan_type,
                'monthly_price' => $plan->price,
                'end_date' => $this->calculateSubscriptionEndDateFrom($extendable->end_date, $plan->billing_period),
                'status' => 'active',
            ]);
            $subscription = $extendable->fresh();
        } else {
            $start = now()->startOfDay();
            $subscription = Subscription::create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'plan_type' => $plan->plan_type,
                'monthly_price' => $plan->price,
                'start_date' => $start,
                'end_date' => $this->calculateSubscriptionEndDateFrom($start, $plan->billing_period),
                'status' => 'active',
            ]);

            $company->subscriptions()
                ->where('id', '!=', $subscription->id)
                ->where('status', 'active')
                ->update(['status' => 'expired']);
        }

        $company->update(['status' => 'active']);

        return $subscription;
    }

    public function suspendCompany(\App\Models\Company $company): void
    {
        $company->update(['status' => 'suspended']);

        $company->subscriptions()->where('status', 'active')->update([
            'status' => 'suspended',
        ]);
    }

    public function activateCompany(\App\Models\Company $company): void
    {
        $hasValidSubscription = $company->subscriptions()
            ->whereIn('status', ['active', 'suspended'])
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->exists();

        if (! $hasValidSubscription) {
            throw new \RuntimeException('Cannot activate a company without a valid subscription. The company must renew.');
        }

        $company->update(['status' => 'active']);

        $company->subscriptions()
            ->where('status', 'suspended')
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->update(['status' => 'active']);
    }

    public function canPermanentlyDelete(Company $company): bool
    {
        if ($company->status !== 'active') {
            return true;
        }

        $hasValidSubscription = $company->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->exists();

        if ($hasValidSubscription) {
            return false;
        }

        if ($company->employees()->exists() || $company->users()->exists() || $company->departments()->exists()) {
            return false;
        }

        return true;
    }
}
