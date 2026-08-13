<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\HolidayPolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeCompanyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $employeeUser;

    private User $hrManager;

    private User $departmentManagerUser;

    private User $generalManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Policy Co',
            'email' => 'policy@company.test',
            'address' => 'Address',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee@policy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@policy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->departmentManagerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Dept Manager',
            'email' => 'deptmgr@policy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm@policy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    public function test_employee_sees_their_company_attendance_and_leave_policies(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'allowed_late_minutes' => 15,
            'allowed_early_leave_minutes' => 10,
            'minimum_daily_hours' => 8,
        ]);

        LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 21,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Inactive Leave Type',
            'allocation_value' => 5,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->employeeUser)->getJson('/api/employee/company-policies');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attendance_policy.work_start_time', '09:00:00')
            ->assertJsonPath('data.attendance_policy.work_end_time', '17:00:00')
            ->assertJsonPath('data.attendance_policy.allowed_late_minutes', 15)
            ->assertJsonPath('data.attendance_policy.allowed_early_leave_minutes', 10)
            ->assertJsonPath('data.attendance_policy.minimum_daily_hours', 8)
            ->assertJsonCount(1, 'data.leave_policies')
            ->assertJsonPath('data.leave_policies.0.name', 'Annual Leave')
            ->assertJsonPath('data.leave_policies.0.allocation_value', 21);
    }

    public function test_hr_department_manager_and_general_manager_can_also_view_policies(): void
    {
        foreach (['hrManager', 'departmentManagerUser', 'generalManager'] as $userProp) {
            $this->actingAs($this->{$userProp})
                ->getJson('/api/employee/company-policies')
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    public function test_missing_attendance_policy_and_leave_types_returns_success_with_empty_data(): void
    {
        $response = $this->actingAs($this->employeeUser)->getJson('/api/employee/company-policies');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attendance_policy', null)
            ->assertJsonCount(0, 'data.leave_policies');
    }

    public function test_employee_cannot_see_another_companys_policies(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'allowed_late_minutes' => 15,
            'allowed_early_leave_minutes' => 10,
            'minimum_daily_hours' => 8,
        ]);

        $otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Co',
            'email' => 'other@policy.test',
            'address' => 'Address',
            'phone' => '0922222222',
            'status' => 'active',
        ]);

        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'work_start_time' => '08:00:00',
            'work_end_time' => '16:00:00',
            'allowed_late_minutes' => 5,
            'allowed_early_leave_minutes' => 5,
            'minimum_daily_hours' => 7,
        ]);

        $otherEmployee = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'full_name' => 'Other Employee',
            'email' => 'otheremp@policy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->actingAs($otherEmployee)
            ->getJson('/api/employee/company-policies')
            ->assertOk()
            ->assertJsonPath('data.attendance_policy.work_start_time', '08:00:00');

        $this->actingAs($this->employeeUser)
            ->getJson('/api/employee/company-policies')
            ->assertOk()
            ->assertJsonPath('data.attendance_policy.work_start_time', '09:00:00');
    }

    public function test_employee_sees_their_company_holidays(): void
    {
        Holiday::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'عيد الاستقلال',
            'holiday_type' => 'single_day',
            'start_date' => '2026-04-17',
            'repeats_annually' => true,
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->employeeUser)->getJson('/api/employee/company-holidays');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'عيد الاستقلال')
            ->assertJsonPath('data.0.start_date', '2026-04-17')
            ->assertJsonPath('data.0.repeats_annually', true);
    }

    public function test_missing_holidays_returns_success_with_empty_array(): void
    {
        $response = $this->actingAs($this->employeeUser)->getJson('/api/employee/company-holidays');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_employee_cannot_see_another_companys_holidays(): void
    {
        $otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Co 2',
            'email' => 'other2@policy.test',
            'address' => 'Address',
            'phone' => '0933333333',
            'status' => 'active',
        ]);

        Holiday::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'name' => 'Other Company Holiday',
            'holiday_type' => 'single_day',
            'start_date' => '2026-05-01',
            'repeats_annually' => false,
            'is_default' => false,
        ]);

        $this->actingAs($this->employeeUser)
            ->getJson('/api/employee/company-holidays')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_employee_sees_weekly_holidays_only(): void
    {
        HolidayPolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'weekly_holidays' => ['friday', 'saturday'],
        ]);

        $response = $this->actingAs($this->employeeUser)->getJson('/api/employee/company-holidays');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Saturday')
            ->assertJsonPath('data.0.start_date', null)
            ->assertJsonPath('data.0.end_date', null)
            ->assertJsonPath('data.0.repeats_annually', true)
            ->assertJsonPath('data.1.name', 'Friday');

        $this->assertNotEmpty($response->json('data.0.id'));
        $this->assertArrayHasKey('id', $response->json('data.0'));
        $this->assertArrayHasKey('name', $response->json('data.0'));
        $this->assertArrayHasKey('start_date', $response->json('data.0'));
        $this->assertArrayHasKey('end_date', $response->json('data.0'));
        $this->assertArrayHasKey('repeats_annually', $response->json('data.0'));
    }

    public function test_employee_sees_official_and_weekly_holidays_together(): void
    {
        Holiday::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'عيد الاستقلال',
            'holiday_type' => 'single_day',
            'start_date' => '2026-04-17',
            'repeats_annually' => true,
            'is_default' => true,
        ]);

        HolidayPolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'weekly_holidays' => ['friday', 'friday', 'saturday'],
        ]);

        $response = $this->actingAs($this->employeeUser)->getJson('/api/employee/company-holidays');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'عيد الاستقلال')
            ->assertJsonPath('data.0.start_date', '2026-04-17')
            ->assertJsonPath('data.1.name', 'Saturday')
            ->assertJsonPath('data.2.name', 'Friday');
    }

    public function test_employee_cannot_see_another_companys_weekly_holidays(): void
    {
        $otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Co Weekly',
            'email' => 'other-weekly@policy.test',
            'address' => 'Address',
            'phone' => '0944444444',
            'status' => 'active',
        ]);

        HolidayPolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'weekly_holidays' => ['sunday'],
        ]);

        HolidayPolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'weekly_holidays' => ['friday'],
        ]);

        $otherEmployee = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'full_name' => 'Other Weekly Employee',
            'email' => 'otherweekly@policy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->actingAs($this->employeeUser)
            ->getJson('/api/employee/company-holidays')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Friday');

        $this->actingAs($otherEmployee)
            ->getJson('/api/employee/company-holidays')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sunday');
    }
}
