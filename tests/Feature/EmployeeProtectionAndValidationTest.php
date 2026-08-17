<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeProtectionAndValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $hrManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Protection Co',
            'email' => 'protection@company.test',
            'address' => 'Damascus',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Human Resources',
            'is_active' => true,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@protection.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    private function makeEmployee(string $email): Employee
    {
        $user = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Test Employee',
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
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);
    }

    // ── 1) Employee deletion protection ────────────────────────────────

    public function test_deleting_employee_without_history_actually_deletes(): void
    {
        $employee = $this->makeEmployee('nohistory@protection.test');
        $this->actingAs($this->hrManager);

        $this->deleteJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Employee deleted successfully.']);

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
        $this->assertDatabaseMissing('users', ['email' => 'nohistory@protection.test']);
    }

    public function test_deleting_employee_with_attendance_history_freezes_instead_of_deleting(): void
    {
        $employee = $this->makeEmployee('hashistory@protection.test');

        AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'work_date' => now()->toDateString(),
            'status' => AttendanceRecord::STATUS_COMPLETED,
            'attendance_type' => AttendanceRecord::TYPE_PRESENT,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $this->actingAs($this->hrManager);

        $this->deleteJson("/api/hr/employees/{$employee->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot delete this employee because they have related records. The account was frozen instead.');

        // Nothing was deleted - only frozen.
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['email' => 'hashistory@protection.test', 'status' => 'inactive']);
        $this->assertDatabaseHas('attendance_records', ['employee_id' => $employee->id]);
    }

    // ── 2) HR Manager last-one protection ──────────────────────────────
    // Note: only a General Manager can call this endpoint (role:general_manager
    // middleware), so "HR manager deletes their own account" can never actually
    // be reached over HTTP today - the self-delete guard in the controller
    // remains as defensive code for that check regardless.

    private function makeGeneralManager(string $email): User
    {
        return User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => $email,
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    public function test_cannot_delete_last_hr_manager_in_company(): void
    {
        $generalManager = $this->makeGeneralManager('gm@protection.test');
        $this->actingAs($generalManager);

        $this->deleteJson("/api/companies/{$this->company->id}/hr-managers/{$this->hrManager->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot delete the last HR manager in the company.');

        $this->assertDatabaseHas('users', ['id' => $this->hrManager->id]);
    }

    public function test_hr_manager_can_be_deleted_when_another_one_exists_and_has_no_history(): void
    {
        $secondHr = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Second HR',
            'email' => 'hr2@protection.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $generalManager = $this->makeGeneralManager('gm2@protection.test');
        $this->actingAs($generalManager);

        $this->deleteJson("/api/companies/{$this->company->id}/hr-managers/{$secondHr->id}")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'HR manager deleted successfully.']);

        $this->assertDatabaseMissing('users', ['id' => $secondHr->id]);
        $this->assertDatabaseHas('users', ['id' => $this->hrManager->id]);
    }

    // ── 3) hire_date validation ─────────────────────────────────────────

    public function test_store_employee_rejects_future_hire_date(): void
    {
        $this->actingAs($this->hrManager);

        $response = $this->postJson('/api/hr/employees', [
            'full_name' => 'Future Hire',
            'email' => 'future@protection.test',
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => now()->addYear()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.hire_date.0', 'Hire date cannot be in the future.');
    }

    public function test_store_employee_accepts_todays_hire_date(): void
    {
        $this->actingAs($this->hrManager);

        $response = $this->postJson('/api/hr/employees', [
            'full_name' => 'Today Hire',
            'email' => 'today@protection.test',
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'phone' => '0911111111',
            'hire_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
    }

    // ── 4) phone validation ──────────────────────────────────────────────

    public function test_store_employee_rejects_invalid_phone_format(): void
    {
        $this->actingAs($this->hrManager);

        $response = $this->postJson('/api/hr/employees', [
            'full_name' => 'Bad Phone',
            'email' => 'badphone@protection.test',
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2024-01-01',
            'phone' => '+963999999999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.phone.0', 'Phone number must start with 09 and contain 10 digits.');
    }

    public function test_store_employee_accepts_valid_phone_format(): void
    {
        $this->actingAs($this->hrManager);

        $response = $this->postJson('/api/hr/employees', [
            'full_name' => 'Good Phone',
            'email' => 'goodphone@protection.test',
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2024-01-01',
            'phone' => '0912345678',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'goodphone@protection.test', 'phone' => '0912345678']);
    }
}
