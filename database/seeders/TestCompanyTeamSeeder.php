<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HolidayPolicy;
use App\Models\LeaveType;
use App\Models\SalaryRule;
use App\Models\User;
use App\Services\LeaveBalanceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * ينشئ شركة واحدة مع: HR Manager + Department Manager + Employee
 * لتسهيل اختبار مسار الإجازات والموافقات بالكامل.
 */
class TestCompanyTeamSeeder extends Seeder
{
    public function run(): void
    {
        $suffix = Str::random(5);
        $password = 'password123';

        $company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Khibrat Team Test Corp',
            'address' => 'Damascus, Syria',
            'phone' => '+963900000000',
            'email' => "team_info_{$suffix}@khibrat.dev",
            'domain' => 'khibrat-team.dev',
            'payroll_currency' => 'SYP',
            'status' => 'active',
        ]);

        HolidayPolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'weekly_holidays' => ['friday'],
        ]);

        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'minimum_daily_hours' => 8,
            'allows_overtime' => true,
            'work_days' => ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'],
        ]);

        foreach ([
            ['rule_type' => 'overtime_hour', 'time_unit' => 'hour', 'value' => 150],
            ['rule_type' => 'overtime_day', 'time_unit' => 'day', 'value' => 150],
        ] as $rule) {
            SalaryRule::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'rule_type' => $rule['rule_type'],
                'time_unit' => $rule['time_unit'],
                'operation' => 'addition',
                'value_type' => 'percent',
                'value' => $rule['value'],
                'is_active' => true,
            ]);
        }

        $hrDepartment = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name' => 'Human Resources',
            'is_active' => true,
        ]);

        $engineeringDepartment = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name' => 'Software Engineering',
            'is_active' => true,
        ]);

        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 21,
            'allocation_unit' => 'days',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $leaveBalanceService = app(LeaveBalanceService::class);

        // --- HR Manager ---
        $hrEmail = "hr_{$suffix}@test.com";
        $hrUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'full_name' => 'Test HR Manager',
            'email' => $hrEmail,
            'password_hash' => bcrypt($password),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $hrEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'user_id' => $hrUser->id,
            'department_id' => $hrDepartment->id,
            'job_title' => 'HR Manager',
            'base_salary' => 2000.00,
            'hire_date' => now()->subYears(2),
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
        $hrDepartment->update(['manager_id' => $hrEmployee->id]);
        $leaveBalanceService->initializeForEmployee($hrEmployee);

        // --- Department Manager ---
        $managerEmail = "manager_{$suffix}@test.com";
        $managerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'full_name' => 'Test Department Manager',
            'email' => $managerEmail,
            'password_hash' => bcrypt($password),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $managerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'user_id' => $managerUser->id,
            'department_id' => $engineeringDepartment->id,
            'job_title' => 'Engineering Manager',
            'base_salary' => 1800.00,
            'hire_date' => now()->subYear(),
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
        $engineeringDepartment->update(['manager_id' => $managerEmployee->id]);
        $leaveBalanceService->initializeForEmployee($managerEmployee);

        // --- Regular Employee (same department as manager) ---
        $employeeEmail = "employee_{$suffix}@test.com";
        $employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'full_name' => 'Test Employee',
            'email' => $employeeEmail,
            'password_hash' => bcrypt($password),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'user_id' => $employeeUser->id,
            'department_id' => $engineeringDepartment->id,
            'job_title' => 'Software Engineer',
            'base_salary' => 1200.00,
            'hire_date' => now()->subMonths(6),
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
        $leaveBalanceService->initializeForEmployee($employee);

        $hrToken = $hrUser->createToken('test-hr-token')->plainTextToken;
        $managerToken = $managerUser->createToken('test-manager-token')->plainTextToken;
        $employeeToken = $employeeUser->createToken('test-employee-token')->plainTextToken;

        $this->command->info('==================================================');
        $this->command->info('✅ Test Company Team Seeded Successfully!');
        $this->command->info('--------------------------------------------------');
        $this->command->info('Company ID      : ' . $company->id);
        $this->command->info('Leave Type ID   : ' . $leaveType->id);
        $this->command->info('Eng. Dept ID    : ' . $engineeringDepartment->id);
        $this->command->info('--------------------------------------------------');
        $this->command->info('HR Email        : ' . $hrEmail);
        $this->command->info('HR Password     : ' . $password);
        $this->command->info('HR Token        : ' . $hrToken);
        $this->command->info('--------------------------------------------------');
        $this->command->info('Manager Email   : ' . $managerEmail);
        $this->command->info('Manager Password: ' . $password);
        $this->command->info('Manager Token   : ' . $managerToken);
        $this->command->info('--------------------------------------------------');
        $this->command->info('Employee Email  : ' . $employeeEmail);
        $this->command->info('Employee Password: ' . $password);
        $this->command->info('Employee Token  : ' . $employeeToken);
        $this->command->info('==================================================');
    }
}
