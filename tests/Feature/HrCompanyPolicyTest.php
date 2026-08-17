<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HrCompanyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hrManager;

    private User $generalManager;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'HR Policy Co',
            'email' => 'hrpolicy@company.test',
            'address' => 'Address',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@hrpolicy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm@hrpolicy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Employee',
            'email' => 'emp@hrpolicy.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'allowed_late_minutes' => 10,
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
            'name' => 'Inactive Leave',
            'allocation_value' => 5,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => false,
        ]);
    }

    public function test_hr_manager_can_view_company_policies_via_hr_routes(): void
    {
        $this->actingAs($this->hrManager)
            ->getJson('/api/hr/company-policies/leave-types')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->hrManager)
            ->getJson('/api/hr/company-policies/attendance-policy')
            ->assertOk()
            ->assertJsonPath('data.work_start_time', '09:00:00');
    }

    public function test_general_manager_can_also_view_hr_company_policy_routes(): void
    {
        $this->actingAs($this->generalManager)
            ->getJson('/api/hr/company-policies/leave-types')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_hr_manager_can_view_company_policies_via_companies_get_routes(): void
    {
        $this->actingAs($this->hrManager)
            ->getJson('/api/companies/'.$this->company->id.'/leave-types')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->hrManager)
            ->getJson('/api/companies/'.$this->company->id.'/attendance-policy')
            ->assertOk()
            ->assertJsonPath('data.work_start_time', '09:00:00');
    }

    public function test_employee_cannot_access_hr_company_policy_routes(): void
    {
        $this->actingAs($this->employeeUser)
            ->getJson('/api/hr/company-policies/leave-types')
            ->assertForbidden();
    }

    public function test_hr_manager_cannot_modify_company_policies(): void
    {
        $this->actingAs($this->hrManager)
            ->postJson('/api/companies/'.$this->company->id.'/leave-types', [
                'leave_types' => [],
            ])
            ->assertForbidden();
    }
}
