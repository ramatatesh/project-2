<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\SalaryRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeaveAndOvertimeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $managerUser;

    private Employee $managerEmployee;

    private User $hrManager;

    private User $employeeUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Workflow Co',
            'email' => 'workflow@company.test',
            'address' => 'Damascus',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $this->managerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Dept Manager',
            'email' => 'manager@workflow.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->managerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->managerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Engineering Manager',
            'base_salary' => 3000,
            'hire_date' => '2020-01-01',
            'is_active' => true,
        ]);

        $this->department->update(['manager_id' => $this->managerEmployee->id]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@workflow.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee@workflow.test',
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
    }

    // ======================= Leave Requests =======================

    private function makeLeaveType(int $allocation = 10): LeaveType
    {
        return LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => $allocation,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);
    }

    public function test_leave_action_cannot_be_executed_twice_on_the_same_request(): void
    {
        $leaveType = $this->makeLeaveType(10);

        $leaveRequest = LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'requested_value' => 2,
            'status' => 'pending_department_manager',
        ]);

        $first = $this->actingAs($this->managerUser)->postJson("/api/management/leaves/{$leaveRequest->id}/action", [
            'action' => 'approve',
            'role_context' => 'manager',
        ]);
        $first->assertOk()->assertJsonPath('data.status', 'pending_hr');

        $second = $this->actingAs($this->managerUser)->postJson("/api/management/leaves/{$leaveRequest->id}/action", [
            'action' => 'approve',
            'role_context' => 'manager',
        ]);
        $second->assertStatus(422)
            ->assertJsonPath('message', 'Request is not pending department manager review.');

        $this->assertSame('pending_hr', $leaveRequest->fresh()->status);
    }

    public function test_manager_approval_rechecks_balance_and_blocks_when_insufficient(): void
    {
        $leaveType = $this->makeLeaveType(3);

        LeaveBalance::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => now()->year,
            'total_days' => 3,
            'used_days' => 0,
            'remaining_days' => 3,
        ]);

        // An already-approved request that consumes almost the whole balance.
        LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(1)->toDateString(),
            'requested_value' => 2,
            'status' => 'approved',
        ]);

        $pendingRequest = LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'requested_value' => 2,
            'status' => 'pending_department_manager',
        ]);

        $response = $this->actingAs($this->managerUser)->postJson("/api/management/leaves/{$pendingRequest->id}/action", [
            'action' => 'approve',
            'role_context' => 'manager',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot forward: requested duration exceeds remaining leave balance.');

        $this->assertSame('pending_department_manager', $pendingRequest->fresh()->status);
    }

    public function test_cannot_apply_for_leave_with_a_past_start_date(): void
    {
        $leaveType = $this->makeLeaveType(10);

        $response = $this->actingAs($this->employeeUser)->postJson('/api/employee/leaves/apply', [
            'leave_type_id' => $leaveType->id,
            'duration_type' => 'single_day',
            'start_date' => now()->subDays(3)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['start_date']);
    }

    // ======================= Overtime =======================

    private function makeOvertimeRule(): SalaryRule
    {
        return SalaryRule::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'rule_type' => 'overtime_hour',
            'time_unit' => 'hour',
            'operation' => 'addition',
            'value_type' => 'percent',
            'value' => 25,
            'is_active' => true,
        ]);
    }

    public function test_overtime_listing_and_show_work_without_error(): void
    {
        $this->makeOvertimeRule();

        $overtime = OvertimeRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'request_date' => now()->toDateString(),
            'duration_type' => OvertimeRequest::DURATION_HOUR,
            'hours_requested' => 5,
            'status' => OvertimeRequest::STATUS_PENDING_DEPARTMENT_MANAGER,
        ]);

        $this->actingAs($this->employeeUser)
            ->getJson('/api/employee/overtime')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $overtime->id);

        $this->actingAs($this->managerUser)
            ->getJson('/api/management/overtime')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $overtime->id);

        $this->actingAs($this->managerUser)
            ->getJson("/api/management/overtime/{$overtime->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $overtime->id);
    }

    public function test_hr_cannot_approve_more_hours_than_the_department_manager_approved(): void
    {
        $this->makeOvertimeRule();

        $overtime = OvertimeRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'request_date' => now()->toDateString(),
            'duration_type' => OvertimeRequest::DURATION_HOUR,
            'hours_requested' => 10,
            'status' => OvertimeRequest::STATUS_PENDING_DEPARTMENT_MANAGER,
        ]);

        $managerResponse = $this->actingAs($this->managerUser)->postJson("/api/management/overtime/{$overtime->id}/action", [
            'action' => 'approve',
            'role_context' => 'manager',
            'hours_approved' => 5,
        ]);
        $managerResponse->assertOk()->assertJsonPath('data.status', 'pending_hr');

        $hrResponse = $this->actingAs($this->hrManager)->postJson("/api/management/overtime/{$overtime->id}/action", [
            'action' => 'approve',
            'role_context' => 'hr',
            'hours_approved' => 8,
        ]);

        $hrResponse->assertStatus(422)
            ->assertJsonPath('message', 'HR approval cannot exceed the hours approved by the department manager (5).');

        $this->assertSame('pending_hr', $overtime->fresh()->status);
    }

    public function test_invalid_uuid_in_overtime_management_routes_returns_404_not_500(): void
    {
        $this->actingAs($this->hrManager)
            ->getJson('/api/management/overtime/not-a-uuid')
            ->assertStatus(404);

        $this->actingAs($this->hrManager)
            ->postJson('/api/management/overtime/not-a-uuid/action', [
                'action' => 'approve',
                'role_context' => 'hr',
            ])
            ->assertStatus(404);
    }
}
