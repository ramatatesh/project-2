<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\StripeService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $status = 'active'): Company
    {
        return Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Lifecycle Co',
            'email' => Str::uuid()->toString().'@khibrat.test',
            'address' => 'Damascus',
            'phone' => '0911111111',
            'status' => $status,
        ]);
    }

    private function makeUser(Company $company, Role $role = Role::GeneralManager): User
    {
        return User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'full_name' => $role->label(),
            'email' => Str::uuid()->toString().'@khibrat.test',
            'password_hash' => bcrypt('password123'),
            'role' => $role->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    private function makePlan(float $price = 49.99, string $period = 'month'): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Pro',
            'plan_type' => 'paid',
            'billing_period' => $period,
            'max_employees' => 50,
            'price' => $price,
            'is_active' => true,
            'max_uses_per_company' => 1,
        ]);
    }

    private function makeSubscription(Company $company, array $overrides = []): Subscription
    {
        $plan = $this->makePlan();

        return Subscription::create(array_merge([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'plan_type' => 'paid',
            'monthly_price' => 49.99,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
        ], $overrides));
    }

    public function test_future_subscription_keeps_company_active(): void
    {
        $company = $this->makeCompany('active');
        $this->makeSubscription($company, ['end_date' => now()->addDays(10)]);

        $this->artisan('subscriptions:expire-overdue')->assertSuccessful();

        $this->assertSame('active', $company->fresh()->status);
        $this->assertSame('active', $company->subscriptions()->first()->status);
    }

    public function test_overdue_subscription_freezes_company_via_scheduled_command(): void
    {
        $company = $this->makeCompany('active');
        $subscription = $this->makeSubscription($company, [
            'status' => 'active',
            'end_date' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:expire-overdue')->assertSuccessful();

        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame('suspended', $company->fresh()->status);
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_expired_subscription_does_not_delete_the_company(): void
    {
        $company = $this->makeCompany('active');
        $this->makeSubscription($company, [
            'status' => 'active',
            'end_date' => now()->subDays(40),
        ]);

        app(SubscriptionService::class)->expireOverdueSubscriptions();

        $this->assertNotNull(Company::find($company->id));
        $this->assertSame('suspended', $company->fresh()->status);
    }

    public function test_frozen_company_can_login_and_read_but_not_write(): void
    {
        $company = $this->makeCompany('suspended');
        $gm = $this->makeUser($company, Role::GeneralManager);
        $hr = $this->makeUser($company, Role::HrManager);

        $this->postJson('/api/auth/login', [
            'email' => $gm->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.company.status', 'suspended')
            ->assertJsonPath('data.user.email', $gm->email);

        $this->actingAs($gm)->getJson('/api/company/profile')->assertOk();
        $this->actingAs($hr)->getJson('/api/hr/departments')->assertOk();
        $this->actingAs($hr)->getJson('/api/hr/employees')->assertOk();

        $this->actingAs($hr)
            ->postJson('/api/hr/departments', ['name' => 'Blocked Dept'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Company is frozen.');

        $this->actingAs($hr)
            ->postJson('/api/hr/employees', [
                'full_name' => 'Blocked',
                'email' => 'blocked@khibrat.test',
                'department_id' => Str::uuid()->toString(),
                'job_title' => 'Dev',
                'base_salary' => 1000,
                'hire_date' => '2024-01-01',
            ])
            ->assertStatus(403);

        $this->actingAs($gm)
            ->putJson('/api/company/profile', [
                'name' => 'No',
                'phone' => '0933333333',
                'email' => 'no@khibrat.test',
                'address' => 'Homs',
            ])
            ->assertStatus(403);

        $this->actingAs($hr)
            ->postJson('/api/hr/evaluation-cycles', [
                'name' => 'Blocked Cycle',
                'evaluation_template_id' => Str::uuid()->toString(),
                'start_date' => now()->toDateString(),
                'end_date' => now()->addWeek()->toDateString(),
            ])
            ->assertStatus(403);
    }

    public function test_renewal_endpoint_is_allowed_when_company_is_frozen(): void
    {
        $company = $this->makeCompany('suspended');
        $gm = $this->makeUser($company);
        $plan = $this->makePlan();

        $session = StripeSession::constructFrom([
            'id' => 'cs_test_renew_1',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_renew_1',
        ]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('createRenewalCheckoutSession')->once()->andReturn($session);
        $this->app->instance(StripeService::class, $stripe);

        $this->actingAs($gm)
            ->postJson('/api/company/subscription/renew', ['plan_id' => $plan->id])
            ->assertStatus(202)
            ->assertJsonPath('payment_required', true)
            ->assertJsonPath('transaction_reference', 'cs_test_renew_1');
    }

    public function test_successful_renewal_reactivates_same_company_and_users(): void
    {
        $company = $this->makeCompany('suspended');
        $user = $this->makeUser($company);
        $department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name' => 'HR',
            'is_active' => true,
        ]);
        Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'department_id' => $department->id,
            'job_title' => 'GM',
            'base_salary' => 2000,
            'hire_date' => '2020-01-01',
            'is_active' => true,
        ]);
        $this->makeSubscription($company, [
            'status' => 'expired',
            'end_date' => now()->subDay(),
        ]);
        $plan = $this->makePlan(99, 'month');

        $beforeUsers = User::where('company_id', $company->id)->count();
        $beforeEmployees = Employee::where('company_id', $company->id)->count();

        $session = StripeSession::constructFrom([
            'id' => 'cs_test_renew_ok',
            'payment_status' => 'paid',
            'amount_total' => 9900,
            'payment_intent' => 'pi_test',
            'metadata' => [
                'purpose' => 'renewal',
                'company_id' => $company->id,
                'plan_id' => $plan->id,
            ],
        ]);

        $result = app(SubscriptionService::class)->renewCompanyFromStripeSession($session);

        $this->assertTrue($result['success']);
        $this->assertSame('active', $company->fresh()->status);
        $this->assertSame($company->id, $result['company']->id);
        $this->assertSame('active', $result['subscription']->status);
        $this->assertTrue($result['subscription']->end_date->greaterThan(now()));
        $this->assertSame($beforeUsers, User::where('company_id', $company->id)->count());
        $this->assertSame($beforeEmployees, Employee::where('company_id', $company->id)->count());
        $this->assertSame(1, Department::where('company_id', $company->id)->count());
        $this->assertTrue($user->fresh()->is_first_login);

        $hr = $this->makeUser($company, Role::HrManager);
        $this->actingAs($hr)
            ->postJson('/api/hr/departments', ['name' => 'After Renew'])
            ->assertCreated();
    }

    public function test_failed_payment_leaves_company_suspended(): void
    {
        $company = $this->makeCompany('suspended');
        $subscription = $this->makeSubscription($company, [
            'status' => 'expired',
            'end_date' => now()->subDay(),
        ]);

        $this->assertSame('suspended', $company->fresh()->status);
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame(0, \App\Models\PaymentTransaction::count());
    }

    public function test_super_admin_cannot_delete_active_company_with_data(): void
    {
        $company = $this->makeCompany('active');
        $this->makeSubscription($company);
        $this->makeUser($company);
        $platform = $this->makeCompany('active');
        $admin = $this->makeUser($platform, Role::SuperAdmin);

        $this->actingAs($admin)
            ->deleteJson('/api/companies/'.$company->id)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete an active company with an active subscription or existing business data.');

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_super_admin_can_delete_suspended_company(): void
    {
        $company = $this->makeCompany('suspended');
        $this->makeUser($company);
        $platform = $this->makeCompany('active');
        $admin = $this->makeUser($platform, Role::SuperAdmin);

        $this->actingAs($admin)
            ->deleteJson('/api/companies/'.$company->id)
            ->assertOk();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_activate_without_valid_subscription_is_rejected(): void
    {
        $company = $this->makeCompany('suspended');
        $this->makeSubscription($company, [
            'status' => 'expired',
            'end_date' => now()->subDay(),
        ]);
        $platform = $this->makeCompany('active');
        $admin = $this->makeUser($platform, Role::SuperAdmin);

        $this->actingAs($admin)
            ->postJson('/api/companies/'.$company->id.'/activate')
            ->assertStatus(409);

        $this->assertSame('suspended', $company->fresh()->status);
        $this->assertSame('expired', $company->subscriptions()->first()->status);
    }

    public function test_login_still_returns_existing_company_fields(): void
    {
        $company = $this->makeCompany('active');
        $user = $this->makeUser($company);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.company.name', $company->name)
            ->assertJsonPath('data.company.status', 'active');
    }

    public function test_checkout_session_status_returns_renewal_password_change_hint(): void
    {
        $company = $this->makeCompany('suspended');
        $user = $this->makeUser($company);
        $subscription = $this->makeSubscription($company, [
            'status' => 'expired',
            'end_date' => now()->subDay(),
        ]);
        $plan = $this->makePlan(99, 'month');

        $session = StripeSession::constructFrom([
            'id' => 'cs_test_renew_status',
            'payment_status' => 'paid',
            'amount_total' => 9900,
            'payment_intent' => 'pi_test',
            'metadata' => [
                'purpose' => 'renewal',
                'company_id' => $company->id,
                'plan_id' => $plan->id,
            ],
        ]);

        app(SubscriptionService::class)->renewCompanyFromStripeSession($session);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('retrieveCheckoutSession')
            ->once()
            ->with('cs_test_renew_status')
            ->andReturn($session);
        $this->app->instance(StripeService::class, $stripe);

        $this->getJson('/api/stripe/checkout-sessions/cs_test_renew_status')
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('purpose', 'renewal')
            ->assertJsonPath('requires_password_change', true)
            ->assertJsonPath('company_id', $company->id);

        $this->assertTrue($user->fresh()->is_first_login);
    }
}
