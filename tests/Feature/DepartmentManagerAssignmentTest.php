<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\SalaryAdvancePolicy;
use App\Models\SalaryRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepartmentManagerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $engineering;

    private Department $sales;

    private User $hrManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Assign Co',
            'email' => 'assign@company.test',
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
            'email' => 'hr@assign.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    private function makeEmployee(Department $department, string $email): Employee
    {
        $user = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Employee '.$email,
            'email' => $email,
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        return Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'job_title' => 'Staff',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);
    }

    public function test_departments_index_includes_the_managers_full_name(): void
    {
        $employee = $this->makeEmployee($this->engineering, 'namecheck@assign.test');
        $this->engineering->update(['manager_id' => $employee->id]);
        $employee->user->update(['role' => Role::DepartmentManager->value]);

        $response = $this->actingAs($this->hrManager)->getJson('/api/hr/departments');

        $response->assertOk();
        $departments = collect($response->json('data'));
        $engineering = $departments->firstWhere('id', $this->engineering->id);

        $this->assertSame($employee->id, $engineering['manager']['id']);
        $this->assertSame('Employee namecheck@assign.test', $engineering['manager']['full_name']);
        $this->assertSame('Staff', $engineering['manager']['job_title']);
    }

    public function test_assigning_an_employee_from_the_department_promotes_them_to_manager(): void
    {
        $employee = $this->makeEmployee($this->engineering, 'promote1@assign.test');

        $response = $this->actingAs($this->hrManager)->putJson("/api/hr/departments/{$this->engineering->id}", [
            'manager_id' => $employee->id,
        ]);

        $response->assertOk();
        $this->assertSame($employee->id, $this->engineering->fresh()->manager_id);
        $this->assertSame(Role::DepartmentManager->value, $employee->user->fresh()->role);
    }

    public function test_changing_the_manager_demotes_the_old_one_and_promotes_the_new_one(): void
    {
        $oldManager = $this->makeEmployee($this->engineering, 'old-manager@assign.test');
        $this->engineering->update(['manager_id' => $oldManager->id]);
        $oldManager->user->update(['role' => Role::DepartmentManager->value]);

        $newManager = $this->makeEmployee($this->engineering, 'new-manager@assign.test');

        $response = $this->actingAs($this->hrManager)->putJson("/api/hr/departments/{$this->engineering->id}", [
            'manager_id' => $newManager->id,
        ]);

        $response->assertOk();
        $this->assertSame($newManager->id, $this->engineering->fresh()->manager_id);
        $this->assertSame(Role::DepartmentManager->value, $newManager->user->fresh()->role);
        $this->assertSame(Role::Employee->value, $oldManager->user->fresh()->role);
    }

    public function test_cannot_assign_a_manager_from_a_different_department(): void
    {
        $employeeInSales = $this->makeEmployee($this->sales, 'sales-emp@assign.test');

        $response = $this->actingAs($this->hrManager)->putJson("/api/hr/departments/{$this->engineering->id}", [
            'manager_id' => $employeeInSales->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['manager_id']);
        $this->assertNull($this->engineering->fresh()->manager_id);
        $this->assertSame(Role::Employee->value, $employeeInSales->user->fresh()->role);
    }

    public function test_cannot_assign_an_employee_who_already_manages_another_department(): void
    {
        $manager = $this->makeEmployee($this->sales, 'sales-manager@assign.test');
        $this->sales->update(['manager_id' => $manager->id]);
        $manager->user->update(['role' => Role::DepartmentManager->value]);

        // Move them (as a plain record) into engineering's employee list to isolate the "already
        // manages another department" rule from the "must belong to this department" rule.
        Employee::where('id', $manager->id)->update(['department_id' => $this->engineering->id]);

        $response = $this->actingAs($this->hrManager)->putJson("/api/hr/departments/{$this->engineering->id}", [
            'manager_id' => $manager->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['manager_id']);
        $response->assertJsonPath('errors.manager_id.0', 'This employee is already the manager of another department. Remove them from that department first.');
        $this->assertNull($this->engineering->fresh()->manager_id);
    }

    public function test_creating_a_department_rejects_a_manager_who_already_manages_another_department(): void
    {
        $manager = $this->makeEmployee($this->sales, 'existing-manager@assign.test');
        $this->sales->update(['manager_id' => $manager->id]);
        $manager->user->update(['role' => Role::DepartmentManager->value]);

        $response = $this->actingAs($this->hrManager)->postJson('/api/hr/departments', [
            'name' => 'New Department',
            'manager_id' => $manager->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['manager_id']);
    }

    public function test_department_manager_can_use_employee_self_service_endpoints(): void
    {
        $manager = $this->makeEmployee($this->engineering, 'self-service-manager@assign.test');
        $this->engineering->update(['manager_id' => $manager->id]);
        $manager->user->update(['role' => Role::DepartmentManager->value]);

        $this->actingAs($manager->user->fresh())
            ->getJson('/api/employee/attendance/dashboard')
            ->assertOk();

        $this->actingAs($manager->user->fresh())
            ->getJson('/api/employee/overtime')
            ->assertOk();

        $this->actingAs($manager->user->fresh())
            ->getJson('/api/employee/advances')
            ->assertOk();

        $this->actingAs($manager->user->fresh())
            ->getJson('/api/employee/salaries')
            ->assertOk();
    }

    public function test_department_manager_applying_for_own_leave_skips_straight_to_hr(): void
    {
        $manager = $this->makeEmployee($this->engineering, 'leave-manager@assign.test');
        $this->engineering->update(['manager_id' => $manager->id]);
        $manager->user->update(['role' => Role::DepartmentManager->value]);

        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 10,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($manager->user->fresh())->postJson('/api/employee/leaves/apply', [
            'leave_type_id' => $leaveType->id,
            'duration_type' => 'single_day',
            'start_date' => now()->addDays(3)->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'pending_hr');

        // Their own manager-inbox must never show this request (it never entered that state).
        $this->actingAs($manager->user->fresh())
            ->getJson('/api/management/leaves/inbox')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_department_manager_applying_for_own_overtime_skips_straight_to_hr(): void
    {
        $manager = $this->makeEmployee($this->engineering, 'overtime-manager@assign.test');
        $this->engineering->update(['manager_id' => $manager->id]);
        $manager->user->update(['role' => Role::DepartmentManager->value]);

        SalaryRule::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'rule_type' => 'overtime_hour',
            'time_unit' => 'hour',
            'operation' => 'addition',
            'value_type' => 'percent',
            'value' => 25,
            'is_active' => true,
        ]);

        $response = $this->actingAs($manager->user->fresh())->postJson('/api/employee/overtime/apply', [
            'request_date' => now()->toDateString(),
            'duration_type' => 'hour',
            'units' => 3,
        ]);

        $response->assertStatus(201);
        $this->assertSame(OvertimeRequest::STATUS_PENDING_HR, OvertimeRequest::first()->status);
    }

    public function test_department_manager_applying_for_own_advance_skips_straight_to_hr(): void
    {
        SalaryAdvancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'max_advance_percentage' => 50,
            'max_repayment_months' => 12,
            'allow_multiple_active_advances' => false,
        ]);

        $manager = $this->makeEmployee($this->engineering, 'advance-manager@assign.test');
        $this->engineering->update(['manager_id' => $manager->id]);
        $manager->user->update(['role' => Role::DepartmentManager->value]);

        $response = $this->actingAs($manager->user->fresh())->postJson('/api/employee/advances/apply', [
            'requested_amount' => 100,
            'repayment_months' => 2,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'pending_hr');
    }

    public function test_regular_employee_leave_still_goes_to_pending_department_manager(): void
    {
        $manager = $this->makeEmployee($this->engineering, 'other-manager@assign.test');
        $this->engineering->update(['manager_id' => $manager->id]);
        $manager->user->update(['role' => Role::DepartmentManager->value]);

        $staff = $this->makeEmployee($this->engineering, 'staff@assign.test');

        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 10,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($staff->user->fresh())->postJson('/api/employee/leaves/apply', [
            'leave_type_id' => $leaveType->id,
            'duration_type' => 'single_day',
            'start_date' => now()->addDays(3)->toDateString(),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'pending_department_manager');
    }
}
