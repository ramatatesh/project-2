<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvancePolicy;
use App\Models\User;
use App\Services\SalaryAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalaryAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $hrManager;

    private Employee $managerEmployee;

    private User $employeeUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Advance Co',
            'email' => 'advance@company.test',
            'address' => 'Address',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@advance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $managerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Dept Manager',
            'email' => 'manager@advance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->managerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $managerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Engineering Manager',
            'base_salary' => 3000,
            'hire_date' => '2020-01-01',
            'is_active' => true,
        ]);

        $this->department->update(['manager_id' => $this->managerEmployee->id]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee@advance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        SalaryAdvancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'max_advance_percentage' => 50,
            'max_repayment_months' => 60,
            'allow_multiple_active_advances' => false,
        ]);
    }

    public function test_employee_advances_list_works_without_created_at_error(): void
    {
        SalaryAdvance::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_amount' => 100,
            'repayment_months' => 2,
            'monthly_installment' => 50,
            'status' => SalaryAdvance::STATUS_PENDING_DEPARTMENT_MANAGER,
        ]);

        $this->actingAs($this->employeeUser)
            ->getJson('/api/employee/advances')
            ->assertOk()
            ->assertJsonPath('data.data.0.requested_amount', '100.00');
    }

    public function test_management_advances_list_works_without_created_at_error(): void
    {
        SalaryAdvance::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_amount' => 100,
            'repayment_months' => 2,
            'monthly_installment' => 50,
            'status' => SalaryAdvance::STATUS_PENDING_HR,
        ]);

        $this->actingAs($this->hrManager)
            ->getJson('/api/management/advances')
            ->assertOk();
    }

    public function test_installment_schedule_never_goes_negative_and_sums_to_requested_amount(): void
    {
        // The classic rounding trap: $1 over 60 months (1/60 rounds up to 0.02/month).
        $advance = SalaryAdvance::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_amount' => 1,
            'repayment_months' => 60,
            'monthly_installment' => round(1 / 60, 2),
            'status' => SalaryAdvance::STATUS_APPROVED,
        ]);

        app(SalaryAdvanceService::class)->regenerateInstallments($advance);

        $installments = $advance->installments()->orderBy('due_date')->get();

        $this->assertCount(60, $installments);

        foreach ($installments as $installment) {
            $this->assertGreaterThanOrEqual(0, (float) $installment->amount, 'No installment may be negative.');
        }

        $sum = $installments->sum(fn ($i) => (float) $i->amount);
        $this->assertEqualsWithDelta(1.00, $sum, 0.0001);
    }

    public function test_cannot_submit_second_active_advance_when_multiple_not_allowed(): void
    {
        $this->actingAs($this->employeeUser);

        $this->postJson('/api/employee/advances/apply', [
            'requested_amount' => 100,
            'repayment_months' => 2,
        ])->assertCreated();

        $response = $this->postJson('/api/employee/advances/apply', [
            'requested_amount' => 50,
            'repayment_months' => 2,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'لا يمكنك تقديم طلب سلفة جديد لوجود سلفة نشطة قيد السداد');

        $this->assertSame(1, SalaryAdvance::where('employee_id', $this->employee->id)->count());
    }

    public function test_action_on_nonexistent_advance_returns_404_not_500(): void
    {
        $this->actingAs($this->managerEmployee->user);

        $response = $this->postJson('/api/management/advances/'.Str::uuid()->toString().'/action', [
            'action' => 'approve',
            'role_context' => 'manager',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }
}
