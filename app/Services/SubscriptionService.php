<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function registerCompany(array $data): array
    {
        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        // في حال كانت الباقة مجانية (Free Plan)
        if (strtolower($plan->plan_type) === 'free') {
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

            dispatch(new \App\Jobs\SendRegistrationEmailJob($company->email, $tempPassword));

            return [
                'success' => true,
                'company' => $company,
                'subscription' => $subscription,
                'user' => $user,
            ];
        }

        // في حال كانت الباقة مدفوعة (Paid Plan)
        $company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => $data['name'],
            'email' => $data['email'],
            'domain' => $data['domain'] ?? null,
            'address' => $data['address'],
            'phone' => $data['phone'],
            'status' => 'pending', // أحرف صغيرة
            'payroll_currency' => $data['payroll_currency'] ?? 'SYP',
        ]);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'plan_type' => $plan->plan_type,
            'monthly_price' => $plan->price,
            'start_date' => now()->startOfDay(),
            'end_date' => now()->addMonth()->startOfDay(),
            'status' => 'pending', // أحرف صغيرة
        ]);

        Department::create([
              'id' => Str::uuid()->toString(),
              'company_id' => $company->id,
              'name' => 'Human Resources',
              'is_active' => true,
              'manager_id' => null,
        ]);

        $transactionReference = Str::uuid()->toString();
        $transaction = $this->createPaymentTransaction($company->id, $subscription->id, $plan->price, $transactionReference);

        $paymentUrl = url('/payments/checkout/' . $transactionReference);

        return [
            'success' => true,
            'payment_required' => true,
            'payment_url' => $paymentUrl,
            'transaction_reference' => $transactionReference,
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
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
