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

class DepartmentManagerEmployeeViewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $engineering;

    private Department $sales;

    private User $deptManagerUser;

    private Employee $deptManagerEmployee;

    private Employee $engineeringStaff;

    private Employee $salesStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'DeptView Co',
            'email' => 'deptview@company.test',
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

        $this->deptManagerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Dept Manager',
            'email' => 'deptmgr@deptview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->deptManagerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->deptManagerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->engineering->id,
            'job_title' => 'Engineering Manager',
            'base_salary' => 3000,
            'hire_date' => '2020-01-01',
            'is_active' => true,
        ]);

        $this->engineering->update(['manager_id' => $this->deptManagerEmployee->id]);

        $engineeringStaffUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Engineering Staff',
            'email' => 'engstaff@deptview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->engineeringStaff = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $engineeringStaffUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->engineering->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        $salesStaffUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Sales Staff',
            'email' => 'salesstaff@deptview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->salesStaff = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $salesStaffUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->sales->id,
            'job_title' => 'Sales Rep',
            'base_salary' => 900,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);
    }

    public function test_department_manager_lists_only_employees_in_their_own_department(): void
    {
        $response = $this->actingAs($this->deptManagerUser)->getJson('/api/management/employees');

        $response->assertOk()->assertJsonCount(2, 'data');

        $jobTitles = collect($response->json('data'))->pluck('job_title')->all();
        $this->assertContains('Engineering Manager', $jobTitles);
        $this->assertContains('Developer', $jobTitles);
        $this->assertNotContains('Sales Rep', $jobTitles);
    }

    public function test_department_manager_can_view_details_of_employee_in_their_department(): void
    {
        $response = $this->actingAs($this->deptManagerUser)
            ->getJson("/api/management/employees/{$this->engineeringStaff->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_title', 'Developer');
    }

    public function test_department_manager_cannot_view_employee_from_another_department(): void
    {
        $this->actingAs($this->deptManagerUser)
            ->getJson("/api/management/employees/{$this->salesStaff->id}")
            ->assertStatus(404);
    }

    public function test_regular_employee_cannot_access_department_manager_employee_list(): void
    {
        $employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Plain Employee',
            'email' => 'plain@deptview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->actingAs($employeeUser)
            ->getJson('/api/management/employees')
            ->assertStatus(403);
    }

    public function test_hr_manager_cannot_access_department_manager_only_endpoint(): void
    {
        $hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@deptview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->actingAs($hrManager)
            ->getJson('/api/management/employees')
            ->assertStatus(403);
    }

    public function test_manager_from_another_company_cannot_see_employees_across_companies(): void
    {
        $otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Co',
            'email' => 'other@deptview.test',
            'address' => 'Address',
            'phone' => '0922222222',
            'status' => 'active',
        ]);

        $otherDepartment = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $otherManagerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'full_name' => 'Other Manager',
            'email' => 'othermgr@deptview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $otherManagerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $otherManagerUser->id,
            'company_id' => $otherCompany->id,
            'department_id' => $otherDepartment->id,
            'job_title' => 'Manager',
            'base_salary' => 2000,
            'hire_date' => '2021-01-01',
            'is_active' => true,
        ]);

        $otherDepartment->update(['manager_id' => $otherManagerEmployee->id]);

        $this->actingAs($otherManagerUser)
            ->getJson('/api/management/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($otherManagerUser)
            ->getJson("/api/management/employees/{$this->engineeringStaff->id}")
            ->assertStatus(404);
    }
}
