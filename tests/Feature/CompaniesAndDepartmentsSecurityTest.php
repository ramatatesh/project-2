<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompaniesAndDepartmentsSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ---- 1) Payment webhook must be rejected outright when the secret is not configured ----

    public function test_webhook_is_rejected_when_secret_is_not_configured(): void
    {
        config(['services.payment.webhook_secret' => null]);

        $response = $this->postJson('/api/payments/callback', [
            'transaction_reference' => (string) Str::uuid(),
            'success' => true,
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Webhook secret is not configured.');
    }

    public function test_webhook_is_rejected_when_secret_is_empty_string(): void
    {
        config(['services.payment.webhook_secret' => '']);

        $response = $this->postJson('/api/payments/callback', [
            'transaction_reference' => (string) Str::uuid(),
            'success' => true,
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'Webhook secret is not configured.');
    }

    public function test_webhook_still_rejects_invalid_signature_when_secret_is_configured(): void
    {
        config(['services.payment.webhook_secret' => 'a-real-secret']);

        $response = $this->postJson('/api/payments/callback', [
            'transaction_reference' => (string) Str::uuid(),
            'success' => true,
        ], ['X-Webhook-Signature' => 'wrong-secret']);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Invalid webhook signature.');
    }

    // ---- 2) companies.email must be unique ----

    public function test_company_registration_rejects_duplicate_email_with_validation_error(): void
    {
        $plan = SubscriptionPlan::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Free',
            'plan_type' => 'free',
            'price' => 0,
            'is_active' => true,
        ]);

        Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Existing Co',
            'email' => 'dup@khibrat.test',
            'address' => 'Damascus',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/companies/register', [
            'name' => 'New Co',
            'email' => 'dup@khibrat.test',
            'address' => 'Aleppo',
            'contact_name' => 'Manager',
            'phone' => '0922222222',
            'plan_id' => $plan->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, Company::where('email', 'dup@khibrat.test')->count());
    }

    public function test_companies_email_column_has_a_database_unique_constraint(): void
    {
        Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Co A',
            'email' => 'unique-check@khibrat.test',
            'address' => 'Damascus',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Co B',
            'email' => 'unique-check@khibrat.test',
            'address' => 'Aleppo',
            'phone' => '0922222222',
            'status' => 'active',
        ]);
    }

    // ---- 3) Frozen (suspended) companies must block write actions for HR/GM but not Super Admin, and never block reads ----

    private function makeCompanyWithStatus(string $status): Company
    {
        return Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Frozen Test Co',
            'email' => Str::uuid()->toString().'@khibrat.test',
            'address' => 'Damascus',
            'phone' => '0911111111',
            'status' => $status,
        ]);
    }

    private function makeUser(Company $company, Role $role): User
    {
        return User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'full_name' => $role->label(),
            'email' => Str::uuid()->toString().'@khibrat.test',
            'password_hash' => bcrypt('password'),
            'role' => $role->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    public function test_general_manager_cannot_update_company_profile_when_frozen(): void
    {
        $company = $this->makeCompanyWithStatus('suspended');
        $gm = $this->makeUser($company, Role::GeneralManager);

        $this->actingAs($gm)
            ->putJson('/api/company/profile', [
                'name' => 'New Name',
                'phone' => '0933333333',
                'email' => 'new@khibrat.test',
                'address' => 'Homs',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Company is frozen.');
    }

    public function test_general_manager_can_still_read_company_profile_when_frozen(): void
    {
        $company = $this->makeCompanyWithStatus('suspended');
        $gm = $this->makeUser($company, Role::GeneralManager);

        $this->actingAs($gm)
            ->getJson('/api/company/profile')
            ->assertOk();
    }

    public function test_hr_manager_cannot_create_department_when_company_is_frozen(): void
    {
        $company = $this->makeCompanyWithStatus('suspended');
        $hr = $this->makeUser($company, Role::HrManager);

        $this->actingAs($hr)
            ->postJson('/api/hr/departments', ['name' => 'New Dept'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Company is frozen.');
    }

    public function test_hr_manager_can_still_list_departments_when_company_is_frozen(): void
    {
        $company = $this->makeCompanyWithStatus('suspended');
        $hr = $this->makeUser($company, Role::HrManager);

        $this->actingAs($hr)
            ->getJson('/api/hr/departments')
            ->assertOk();
    }

    public function test_frozen_company_middleware_exempts_super_admin(): void
    {
        $company = $this->makeCompanyWithStatus('suspended');
        $superAdmin = $this->makeUser($company, Role::SuperAdmin);

        $middleware = new \App\Http\Middleware\EnsureCompanyIsNotFrozen();

        $request = \Illuminate\Http\Request::create('/api/companies/'.$company->id.'/payroll-currency', 'PUT');
        $request->setUserResolver(fn () => $superAdmin);
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route(['PUT'], '/companies/{company}/payroll-currency', []))
            ->bind($request)
            ->setParameter('company', $company));

        $called = false;
        $response = $middleware->handle($request, function ($req) use (&$called) {
            $called = true;

            return new \Illuminate\Http\Response('ok');
        });

        $this->assertTrue($called, 'Super Admin must pass through even when the company is frozen.');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_general_manager_can_update_company_profile_when_active(): void
    {
        $company = $this->makeCompanyWithStatus('active');
        $gm = $this->makeUser($company, Role::GeneralManager);

        $this->actingAs($gm)
            ->putJson('/api/company/profile', [
                'name' => 'New Name',
                'phone' => '0933333333',
                'email' => 'new-active@khibrat.test',
                'address' => 'Homs',
            ])
            ->assertOk();
    }

    // ---- 4) manager_id must be scoped to the current company (regression test for existing protection) ----

    public function test_cannot_assign_a_manager_from_another_company_to_a_department(): void
    {
        $company = $this->makeCompanyWithStatus('active');
        $hr = $this->makeUser($company, Role::HrManager);

        $otherCompany = $this->makeCompanyWithStatus('active');
        $otherDepartment = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'name' => 'Other Dept',
            'is_active' => true,
        ]);
        $otherEmployeeUser = $this->makeUser($otherCompany, Role::Employee);
        $otherEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $otherEmployeeUser->id,
            'company_id' => $otherCompany->id,
            'department_id' => $otherDepartment->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        $response = $this->actingAs($hr)->postJson('/api/hr/departments', [
            'name' => 'Cross Company Dept',
            'manager_id' => $otherEmployee->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['manager_id']);
    }

    public function test_can_assign_a_manager_from_the_same_company_to_a_department(): void
    {
        $company = $this->makeCompanyWithStatus('active');
        $hr = $this->makeUser($company, Role::HrManager);

        $department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name' => 'Home Dept',
            'is_active' => true,
        ]);
        $employeeUser = $this->makeUser($company, Role::Employee);
        $employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $employeeUser->id,
            'company_id' => $company->id,
            'department_id' => $department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        $response = $this->actingAs($hr)->postJson('/api/hr/departments', [
            'name' => 'Same Company Dept',
            'manager_id' => $employee->id,
        ]);

        $response->assertStatus(201);
    }
}
