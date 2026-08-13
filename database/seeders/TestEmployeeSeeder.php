<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestEmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $uniqueEmail = 'employee_' . Str::random(5) . '@test.com';

        // 1. إنشاء الشركة
        $company = Company::create([
            'id'               => Str::uuid()->toString(),
            'name'             => 'Khibrat Test Corp',
            'address'          => 'Damascus, Syria',
            'phone'            => '+963999999999',
            'email'            => 'info_' . Str::random(4) . '@khibrat.dev',
            'domain'           => 'khibrat.dev',
            'payroll_currency' => 'SYP',
            'status'           => 'active',
        ]);

        // 2. إنشاء الأقسام
        $engineeringDept = Department::create([
            'id'         => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name'       => 'Software Engineering',
        ]);

        $hrDept = Department::create([
            'id'         => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name'       => 'Human Resources',
        ]);

        // 3. المدير العام (General Manager) - تابع لقسم الهندسة
        $generalManagerEmail = 'general_manager_' . Str::random(5) . '@test.com';
        $generalManagerUser = User::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'full_name'     => 'General Manager',
            'email'         => $generalManagerEmail,
            'password_hash' => bcrypt('password123'),
            'role'          => Role::GeneralManager->value,
        ]);

        $gmEmployee = Employee::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'user_id'       => $generalManagerUser->id,
            'department_id' => $engineeringDept->id,
            'job_title'     => 'General Manager',
            'base_salary'   => 3000.00,
            'hire_date'     => now()->subYears(3),
        ]);

        // 4. مدير الموارد البشرية (HR Manager) - تابع لقسم الموارد البشرية
        $hrManagerEmail = 'hr_manager_' . Str::random(5) . '@test.com';
        $hrManagerUser = User::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'full_name'     => 'HR Manager',
            'email'         => $hrManagerEmail,
            'password_hash' => bcrypt('password123'),
            'role'          => Role::HrManager->value,
        ]);

        $hrEmployee = Employee::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'user_id'       => $hrManagerUser->id,
            'department_id' => $hrDept->id,
            'job_title'     => 'HR Director',
            'base_salary'   => 2000.00,
            'hire_date'     => now()->subYears(2),
        ]);

        // تعيين مدير قسم الموارد البشرية
        $hrDept->update(['manager_id' => $hrEmployee->id]);

        // 5. مدير قسم الهندسة (Department Manager) - تابع لقسم الهندسة
        $departmentManagerEmail = 'department_manager_' . Str::random(5) . '@test.com';
        $departmentManagerUser = User::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'full_name'     => 'Department Manager',
            'email'         => $departmentManagerEmail,
            'password_hash' => bcrypt('password123'),
            'role'          => Role::DepartmentManager->value,
        ]);

        $deptManagerEmployee = Employee::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'user_id'       => $departmentManagerUser->id,
            'department_id' => $engineeringDept->id,
            'job_title'     => 'Engineering Manager',
            'base_salary'   => 1800.00,
            'hire_date'     => now()->subYear(),
        ]);

        // تعيين مدير قسم الهندسة
        $engineeringDept->update(['manager_id' => $deptManagerEmployee->id]);

        // 6. موظف البرمجيات (Employee) - تابع لقسم الهندسة
        $user = User::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'full_name'     => 'Rama Tatesh',
            'email'         => $uniqueEmail,
            'password_hash' => bcrypt('password123'),
            'role'          => Role::Employee->value ?? 'employee',
        ]);

        $employee = Employee::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'user_id'       => $user->id,
            'department_id' => $engineeringDept->id,
            'job_title'     => 'Software Engineer',
            'base_salary'   => 1200.00,
            'hire_date'     => now()->subMonths(6),
        ]);

        // 7. إنشاء نوع إجازة
        $leaveType = LeaveType::create([
            'id'               => Str::uuid()->toString(),
            'company_id'       => $company->id,
            'name'             => 'Annual Leave',
            'allocation_value' => 30,
            'allocation_unit'  => 'days',
        ]);

        // 8. إنشاء سجلات رواتب
        $now = now();
        $salaryRecords = [
            [
                'month' => (int) $now->copy()->subMonths(3)->month,
                'year' => (int) $now->copy()->subMonths(3)->year,
                'base_salary' => 1200.00,
                'overtime_amount' => 200.00,
                'bonus_amount' => 0,
                'manual_bonus' => 0,
                'late_deduction' => 0,
                'absent_deduction' => 0,
                'loan_deduction' => 0,
                'manual_deduction' => 0,
                'status' => SalaryRecord::STATUS_PAID,
            ],
            [
                'month' => (int) $now->month,
                'year' => (int) $now->year,
                'base_salary' => 1200.00,
                'overtime_amount' => 0,
                'bonus_amount' => 0,
                'manual_bonus' => 50.00,
                'late_deduction' => 0,
                'absent_deduction' => 80.00,
                'loan_deduction' => 0,
                'manual_deduction' => 70.00,
                'status' => SalaryRecord::STATUS_DRAFT,
            ],
        ];

        foreach ($salaryRecords as $record) {
            $net = round(
                $record['base_salary'] + $record['overtime_amount'] + $record['bonus_amount'] + $record['manual_bonus']
                - $record['late_deduction'] - $record['absent_deduction'] - $record['loan_deduction'] - $record['manual_deduction'],
                2
            );

            SalaryRecord::create(array_merge($record, [
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'net_salary' => $net,
                'closed_by' => $record['status'] === SalaryRecord::STATUS_PAID ? $generalManagerUser->id : null,
                'closed_at' => $record['status'] === SalaryRecord::STATUS_PAID ? now() : null,
            ]));
        }

        $generalManagerToken = $generalManagerUser->createToken('test-general-manager-token')->plainTextToken;
        $hrManagerToken = $hrManagerUser->createToken('test-hr-manager-token')->plainTextToken;
        $departmentManagerToken = $departmentManagerUser->createToken('test-department-manager-token')->plainTextToken;
        $token = $user->createToken('test-token')->plainTextToken;

        $this->command->info('==================================================');
        $this->command->info('✅ Test Data Seeded Successfully!');
        $this->command->info('--------------------------------------------------');
        $this->command->info('Company ID                  : ' . $company->id);
        $this->command->info('Engineering Dept ID         : ' . $engineeringDept->id);
        $this->command->info('HR Dept ID                  : ' . $hrDept->id);
        $this->command->info('--------------------------------------------------');
        $this->command->info('Engineering Dept Manager Employee ID : ' . $deptManagerEmployee->id);
        $this->command->info('HR Dept Manager Employee ID          : ' . $hrEmployee->id);
        $this->command->info('--------------------------------------------------');
        $this->command->info('General Manager Employee ID : ' . $gmEmployee->id);
        $this->command->info('General Manager User ID     : ' . $generalManagerUser->id);
        $this->command->info('General Manager Token       : ' . $generalManagerToken);
        $this->command->info('--------------------------------------------------');
        $this->command->info('HR Manager Employee ID      : ' . $hrEmployee->id);
        $this->command->info('HR Manager User ID          : ' . $hrManagerUser->id);
        $this->command->info('HR Manager Token            : ' . $hrManagerToken);
        $this->command->info('==================================================');
    }
}
