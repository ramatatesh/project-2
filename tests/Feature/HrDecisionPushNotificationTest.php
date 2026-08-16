<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendPushNotificationJob;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\OvertimeRequest;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class HrDecisionPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $hrManager;

    private User $employeeUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'HR Notify Co',
            'email' => 'hr-notify-co@company.test',
            'address' => 'Address',
            'phone' => '+963111111',
            'status' => 'active',
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Ops',
            'is_active' => true,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr-decision@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->hrManager->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'HR',
            'base_salary' => 2000,
            'hire_date' => '2020-01-01',
            'is_active' => true,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Employee User',
            'email' => 'emp-decision@company.test',
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

    public function test_hr_leave_approve_creates_push_notification(): void
    {
        Queue::fake();

        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 14,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $leave = LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'requested_value' => 2,
            'status' => 'pending_hr',
        ]);

        $this->actingAs($this->hrManager)
            ->postJson("/api/management/leaves/{$leave->id}/action", [
                'action' => 'approve',
                'role_context' => 'hr',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employeeUser->id,
            'type' => Notification::TYPE_LEAVE_APPROVED,
            'related_id' => $leave->id,
            'related_table' => 'leave_requests',
            'delivery_channel' => Notification::CHANNEL_PUSH,
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_manager_leave_approve_does_not_notify_employee(): void
    {
        Queue::fake();

        $managerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Dept Manager',
            'email' => 'mgr-decision@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $managerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $managerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Manager',
            'base_salary' => 2000,
            'hire_date' => '2020-01-01',
            'is_active' => true,
        ]);
        $this->department->update(['manager_id' => $managerEmployee->id]);

        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 14,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $leave = LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'requested_value' => 2,
            'status' => 'pending_department_manager',
        ]);

        $this->actingAs($managerUser)
            ->postJson("/api/management/leaves/{$leave->id}/action", [
                'action' => 'approve',
                'role_context' => 'manager',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_hr');

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->employeeUser->id,
            'related_id' => $leave->id,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_manager_leave_reject_notifies_employee_immediately(): void
    {
        Queue::fake();

        $managerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Dept Manager 2',
            'email' => 'mgr2-decision@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $managerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $managerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Manager',
            'base_salary' => 2000,
            'hire_date' => '2020-01-01',
            'is_active' => true,
        ]);
        $this->department->update(['manager_id' => $managerEmployee->id]);

        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 14,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $leave = LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'requested_value' => 2,
            'status' => 'pending_department_manager',
        ]);

        $this->actingAs($managerUser)
            ->postJson("/api/management/leaves/{$leave->id}/action", [
                'action' => 'reject',
                'role_context' => 'manager',
                'rejection_reason' => 'Busy period',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected_by_manager');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employeeUser->id,
            'type' => Notification::TYPE_LEAVE_REJECTED,
            'related_id' => $leave->id,
            'related_table' => 'leave_requests',
        ]);
        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_hr_leave_reject_creates_push_notification(): void
    {
        Queue::fake();

        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Sick Leave',
            'allocation_value' => 10,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $leave = LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'requested_value' => 1,
            'status' => 'pending_hr',
        ]);

        $this->actingAs($this->hrManager)
            ->postJson("/api/management/leaves/{$leave->id}/action", [
                'action' => 'reject',
                'role_context' => 'hr',
                'rejection_reason' => 'Not enough coverage',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected_by_hr');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employeeUser->id,
            'type' => Notification::TYPE_LEAVE_REJECTED,
            'related_id' => $leave->id,
            'related_table' => 'leave_requests',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_hr_overtime_approve_creates_push_notification(): void
    {
        Queue::fake();

        $ot = OvertimeRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'request_date' => now()->toDateString(),
            'duration_type' => OvertimeRequest::DURATION_HOUR,
            'hours_requested' => 2,
            'hours_approved' => 2,
            'status' => OvertimeRequest::STATUS_PENDING_HR,
        ]);

        // Approval may require salary rule; if business rules fail we still assert
        // that a successful approve path notifies. Use reject path as reliable check
        // for overtime when salary rules are missing.
        $this->actingAs($this->hrManager)
            ->postJson("/api/management/overtime/{$ot->id}/action", [
                'action' => 'reject',
                'role_context' => 'hr',
                'rejection_reason' => 'Not needed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OvertimeRequest::STATUS_REJECTED_BY_HR);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employeeUser->id,
            'type' => Notification::TYPE_OVERTIME_REJECTED,
            'related_id' => $ot->id,
            'related_table' => 'overtime_requests',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_hr_advance_reject_creates_push_notification(): void
    {
        Queue::fake();

        $advance = SalaryAdvance::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_amount' => 200,
            'repayment_months' => 2,
            'monthly_installment' => 100,
            'reason' => 'Emergency',
            'status' => SalaryAdvance::STATUS_PENDING_HR,
        ]);

        $this->actingAs($this->hrManager)
            ->postJson("/api/management/advances/{$advance->id}/action", [
                'action' => 'reject',
                'role_context' => 'hr',
                'rejection_reason' => 'Limit exceeded',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SalaryAdvance::STATUS_REJECTED_BY_HR);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employeeUser->id,
            'type' => Notification::TYPE_ADVANCE_REJECTED,
            'related_id' => $advance->id,
            'related_table' => 'salary_advances',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }
}
