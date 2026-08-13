<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeesByDepartmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $engineering;

    private Department $sales;

    private User $hrManager;

    private User $generalManager;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'ByDept Co',
            'email' => 'bydept@company.test',
            'address' => 'Address',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $this->engineering = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $this->sales = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Sales',
            'is_active' => true,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@bydept.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm@bydept.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee@bydept.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->engineering->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        $salesUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Sales Person',
            'email' => 'sales@bydept.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $salesUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->sales->id,
            'job_title' => 'Sales Rep',
            'base_salary' => 900,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);
    }

    public function test_general_manager_can_list_all_employees(): void
    {
        $this->actingAs($this->generalManager)
            ->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_hr_manager_can_still_list_all_employees(): void
    {
        $this->actingAs($this->hrManager)
            ->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_regular_employee_cannot_list_employees(): void
    {
        $this->actingAs($this->employeeUser)
            ->getJson('/api/hr/employees')
            ->assertStatus(403);
    }

    public function test_general_manager_can_view_a_single_employees_details(): void
    {
        $employee = Employee::where('company_id', $this->company->id)
            ->where('job_title', 'Developer')
            ->first();

        $this->actingAs($this->generalManager)
            ->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.job_title', 'Developer');
    }

    public function test_hr_manager_can_view_a_single_employees_details(): void
    {
        $employee = Employee::where('company_id', $this->company->id)
            ->where('job_title', 'Sales Rep')
            ->first();

        $this->actingAs($this->hrManager)
            ->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.job_title', 'Sales Rep');
    }

    public function test_general_manager_can_list_employees_of_a_specific_department(): void
    {
        $response = $this->actingAs($this->generalManager)
            ->getJson("/api/hr/departments/{$this->engineering->id}/employees");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Developer', $response->json('data.0.job_title'));
    }

    public function test_hr_manager_can_list_employees_of_a_specific_department(): void
    {
        $response = $this->actingAs($this->hrManager)
            ->getJson("/api/hr/departments/{$this->sales->id}/employees");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Sales Rep', $response->json('data.0.job_title'));
    }

    public function test_department_employees_list_excludes_other_departments(): void
    {
        $response = $this->actingAs($this->hrManager)
            ->getJson("/api/hr/departments/{$this->engineering->id}/employees");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Developer', $response->json('data.0.job_title'));
    }

    public function test_regular_employee_cannot_list_department_employees(): void
    {
        $this->actingAs($this->employeeUser)
            ->getJson("/api/hr/departments/{$this->engineering->id}/employees")
            ->assertStatus(403);
    }

    public function test_department_from_another_company_returns_404(): void
    {
        $otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Co',
            'email' => 'other@bydept.test',
            'address' => 'Address',
            'phone' => '0922222222',
            'status' => 'active',
        ]);

        $otherDepartment = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'name' => 'Other Dept',
            'is_active' => true,
        ]);

        $this->actingAs($this->hrManager)
            ->getJson("/api/hr/departments/{$otherDepartment->id}/employees")
            ->assertStatus(404);
    }
}
