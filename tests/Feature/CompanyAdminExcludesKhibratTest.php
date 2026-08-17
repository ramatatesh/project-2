<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyAdminExcludesKhibratTest extends TestCase
{
    use RefreshDatabase;

    private const KHIBRAT_EMAIL = 'owner@khibrat.sa';

    private Company $khibrat;

    private User $superAdmin;

    private Company $tenantCompany;

    private SubscriptionPlan $paidPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->khibrat = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Khibrat (Platform Owner)',
            'address' => 'HQ',
            'email' => self::KHIBRAT_EMAIL,
            'payroll_currency' => 'SYP',
            'status' => 'active',
        ]);

        $this->superAdmin = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->khibrat->id,
            'full_name' => 'Super Admin',
            'email' => 'superadmin@khibrat.sa',
            'password_hash' => bcrypt('password'),
            'role' => Role::SuperAdmin->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->paidPlan = SubscriptionPlan::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Standard',
            'plan_type' => 'paid',
            'billing_period' => 'month',
            'max_employees' => 100,
            'price' => 49.99,
            'is_active' => true,
            'max_uses_per_company' => 1,
        ]);

        // Khibrat itself also has a subscription + paid transaction, exactly like the
        // seeder's real setup - proves the exclusion is real, not just "no data present".
        $khibratSubscription = Subscription::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->khibrat->id,
            'plan_id' => $this->paidPlan->id,
            'plan_type' => 'paid',
            'monthly_price' => 49.99,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        PaymentTransaction::create([
            'company_id' => $this->khibrat->id,
            'subscription_id' => $khibratSubscription->id,
            'amount' => 49.99,
            'transaction_reference' => 'khibrat-ref-1',
            'status' => 'paid',
        ]);

        $this->tenantCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Real Tenant Co',
            'email' => 'tenant@company.test',
            'address' => 'Damascus',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $tenantSubscription = Subscription::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->tenantCompany->id,
            'plan_id' => $this->paidPlan->id,
            'plan_type' => 'paid',
            'monthly_price' => 49.99,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        PaymentTransaction::create([
            'company_id' => $this->tenantCompany->id,
            'subscription_id' => $tenantSubscription->id,
            'amount' => 100.00,
            'transaction_reference' => 'tenant-ref-1',
            'status' => 'paid',
        ]);
    }

    public function test_khibrat_does_not_appear_in_the_companies_list(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/companies');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertNotContains('Khibrat (Platform Owner)', $names);
        $this->assertContains('Real Tenant Co', $names);
    }

    public function test_khibrat_is_excluded_from_every_dashboard_stat(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/companies/stats');

        $response->assertOk();

        // Only the real tenant counts - Khibrat itself must never be included.
        $response->assertJsonPath('data.summary.total_companies', 1)
            ->assertJsonPath('data.summary.paid_companies', 1)
            ->assertJsonPath('data.summary.free_companies', 0)
            ->assertJsonPath('data.summary.total_subscriptions', 1)
            ->assertJsonPath('data.summary.total_revenue', 100)
            ->assertJsonPath('data.status_distribution.active', 1);

        $latestNames = collect($response->json('data.latest_registered_platforms'))->pluck('name')->all();
        $this->assertNotContains('Khibrat (Platform Owner)', $latestNames);
        $this->assertContains('Real Tenant Co', $latestNames);

        $monthlyTotal = collect($response->json('data.monthly_subscription_analytics'))->sum('count');
        $this->assertSame(1, $monthlyTotal);
    }
}
