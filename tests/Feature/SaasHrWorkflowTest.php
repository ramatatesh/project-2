<?php

namespace Tests\Feature;

use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasHrWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_plan_registration_creates_company_and_temp_password(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Starter Free',
            'plan_type' => 'standard',
            'billing_period' => 'month',
            'max_employees' => 50,
            'price' => 0,
            'is_active' => true,
            'max_uses_per_company' => 1,
        ]);

        $service = new SubscriptionService();
        $result = $service->registerCompany([
            'name' => 'Acme Labs',
            'email' => 'owner@acme.test',
            'address' => 'Damascus',
            'contact_name' => 'Ahmad',
            'phone' => '+963999999999',
            'plan_id' => $plan->id,
            'payment_status' => 'paid',
        ]);

        $this->assertTrue($result['success']);
        $company = Company::where('email', 'owner@acme.test')->first();
        $this->assertNotNull($company);
        $this->assertSame('active', $company->status);
        $this->assertCount(1, $company->subscriptions);
        $this->assertNotNull($company->users()->first()?->password_hash);
        $this->assertTrue($company->users()->first()?->is_first_login);
    }

    public function test_expired_paid_subscription_suspends_company(): void
    {
        $company = Company::create([
            'name' => 'Expired Co',
            'email' => 'expired@company.test',
            'address' => 'Aleppo',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        Subscription::create([
            'company_id' => $company->id,
            'plan_type' => 'paid',
            'status' => 'expired',
            'start_date' => now()->subMonth(),
            'end_date' => now()->subDay(),
            'monthly_price' => 100,
        ]);

        $service = new SubscriptionService();
        $service->refreshCompanySubscriptionStatus($company);

        $company->refresh();

        $this->assertSame('suspended', $company->status);
    }

    public function test_attendance_policy_can_be_updated_per_company(): void
    {
        $company = Company::create([
            'name' => 'Policy Co',
            'email' => 'policy@company.test',
            'address' => 'Latakia',
            'phone' => '+963111111',
            'status' => 'active',
        ]);

        $policy = AttendancePolicy::create([
            'company_id' => $company->id,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'allowed_late_minutes' => 15,
            'allowed_early_leave_minutes' => 15,
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'minimum_daily_hours' => 8,
            'allows_overtime' => true,
        ]);

        $policy->update([
            'allowed_late_minutes' => 20,
            'minimum_daily_hours' => 9,
        ]);

        $policy->refresh();

        $this->assertSame(20, $policy->allowed_late_minutes);
        $this->assertSame(9, $policy->minimum_daily_hours);
    }
}
