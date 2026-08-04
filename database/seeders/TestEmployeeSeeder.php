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
        // إيميل فريد في كل تشغيل لتفادي أخطاء Unique
        $uniqueEmail = 'employee_' . Str::random(5) . '@test.com';

        // 1. إنشاء شركة
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

        // 2. إنشاء قسم
        $department = Department::create([
            'id'         => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name'       => 'Software Engineering',
        ]);

        // 3. إنشاء حساب مدير الشركة (General Manager)
        $generalManagerEmail = 'general_manager_' . Str::random(5) . '@test.com';
        $generalManagerUser = User::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'full_name'     => 'General Manager',
            'email'         => $generalManagerEmail,
            'password_hash' => bcrypt('password123'),
            'role'          => Role::GeneralManager->value,
        ]);

        Employee::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'user_id'       => $generalManagerUser->id,
            'department_id' => $department->id,
            'job_title'     => 'General Manager',
            'base_salary'   => 3000.00,
            'hire_date'     => now()->subYears(3),
        ]);

        // 4. إنشاء حساب مدير الموارد البشرية (HR Manager)
        $hrManagerEmail = 'hr_manager_' . Str::random(5) . '@test.com';
        $hrManagerUser = User::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'full_name'     => 'HR Manager',
            'email'         => $hrManagerEmail,
            'password_hash' => bcrypt('password123'),
            'role'          => Role::HrManager->value,
        ]);

        Employee::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'user_id'       => $hrManagerUser->id,
            'department_id' => $department->id,
            'job_title'     => 'HR Manager',
            'base_salary'   => 2000.00,
            'hire_date'     => now()->subYears(2),
        ]);

        // 5. إنشاء حساب مدير القسم (Department Manager)
        $departmentManagerEmail = 'department_manager_' . Str::random(5) . '@test.com';
        $departmentManagerUser = User::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'full_name'     => 'Department Manager',
            'email'         => $departmentManagerEmail,
            'password_hash' => bcrypt('password123'),
            'role'          => Role::DepartmentManager->value,
        ]);

        $departmentManagerEmployee = Employee::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'user_id'       => $departmentManagerUser->id,
            'department_id' => $department->id,
            'job_title'     => 'Engineering Manager',
            'base_salary'   => 1800.00,
            'hire_date'     => now()->subYear(),
        ]);

        $department->update(['manager_id' => $departmentManagerEmployee->id]);

        // 6. إنشاء حساب الموظف
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
            'department_id' => $department->id,
            'job_title'     => 'Software Engineer',
            'base_salary'   => 1200.00,
            'hire_date'     => now()->subMonths(6),
        ]);

        // 7. إنشاء نوع إجازة بالحقول الإجبارية المطابقة للميجريشن
        $leaveType = LeaveType::create([
            'id'               => Str::uuid()->toString(),
            'company_id'       => $company->id,
            'name'             => 'Annual Leave',
            'allocation_value' => 30,       // 👈 حقل إجباري
            'allocation_unit'  => 'days',   // 👈 حقل إجباري
        ]);

        // 8. إنشاء سجلات رواتب تجريبية للموظف (لبضعة أشهر سابقة)
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
                'month' => (int) $now->copy()->subMonths(2)->month,
                'year' => (int) $now->copy()->subMonths(2)->year,
                'base_salary' => 1200.00,
                'overtime_amount' => 0,
                'bonus_amount' => 0,
                'manual_bonus' => 0,
                'late_deduction' => 30.00,
                'absent_deduction' => 0,
                'loan_deduction' => 0,
                'manual_deduction' => 0,
                'status' => SalaryRecord::STATUS_PAID,
            ],
            [
                'month' => (int) $now->copy()->subMonths(1)->month,
                'year' => (int) $now->copy()->subMonths(1)->year,
                'base_salary' => 1200.00,
                'overtime_amount' => 150.00,
                'bonus_amount' => 100.00,
                'manual_bonus' => 0,
                'late_deduction' => 50.00,
                'absent_deduction' => 0,
                'loan_deduction' => 100.00,
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
                $record['base_salary']
                + $record['overtime_amount']
                + $record['bonus_amount']
                + $record['manual_bonus']
                - $record['late_deduction']
                - $record['absent_deduction']
                - $record['loan_deduction']
                - $record['manual_deduction'],
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
        $this->command->info('Company ID     : ' . $company->id);
        $this->command->info('Leave Type ID  : ' . $leaveType->id);
        $this->command->info('Department ID  : ' . $department->id);
        $this->command->info('Employee ID    : ' . $employee->id);
        $this->command->info('Salary Records : ' . count($salaryRecords));
        $this->command->info('--------------------------------------------------');
        $this->command->info('General Manager Email    : ' . $generalManagerEmail);
        $this->command->info('General Manager Password : password123');
        $this->command->info('General Manager Token    : ' . $generalManagerToken);
        $this->command->info('--------------------------------------------------');
        $this->command->info('HR Manager Email    : ' . $hrManagerEmail);
        $this->command->info('HR Manager Password : password123');
        $this->command->info('HR Manager Token    : ' . $hrManagerToken);
        $this->command->info('--------------------------------------------------');
        $this->command->info('Department Manager Email    : ' . $departmentManagerEmail);
        $this->command->info('Department Manager Password : password123');
        $this->command->info('Department Manager Token    : ' . $departmentManagerToken);
        $this->command->info('--------------------------------------------------');
        $this->command->info('Employee Email    : ' . $uniqueEmail);
        $this->command->info('Employee Password : password123');
        $this->command->info('Employee Token    : ' . $token);
        $this->command->info('==================================================');
    }
}
