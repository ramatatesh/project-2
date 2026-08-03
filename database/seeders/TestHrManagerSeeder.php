<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestHrManagerSeeder extends Seeder
{
    public function run(): void
    {
        // إيميل فريد في كل تشغيل لتفادي أخطاء Unique
        $uniqueEmail = 'hr_' . Str::random(5) . '@test.com';

        // 1. إنشاء شركة
        $company = Company::create([
            'id'               => Str::uuid()->toString(),
            'name'             => 'Khibrat HR Test Corp',
            'address'          => 'Damascus, Syria',
            'phone'            => '+963988888888',
            'email'            => 'hr_info_' . Str::random(4) . '@khibrat.dev',
            'domain'           => 'khibrat-hr.dev',
            'payroll_currency' => 'SYP',
            'status'           => 'active',
        ]);

        // 2. إنشاء قسم الموارد البشرية (مطلوب عند إنشاء HR في النظام)
        $hrDepartment = Department::create([
            'id'         => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name'       => 'Human Resources',
        ]);

        // 3. إنشاء حساب مستخدم بدور HR Manager
        $user = User::create([
            'id'             => Str::uuid()->toString(),
            'company_id'     => $company->id,
            'full_name'      => 'Test HR Manager',
            'email'          => $uniqueEmail,
            'password_hash'  => bcrypt('password123'),
            'role'           => Role::HrManager->value,
            'status'         => 'active',
            'is_first_login' => false,
        ]);

        // 4. إنشاء ملف الموظف المرتبط بحساب الـ HR
        $employee = Employee::create([
            'id'              => Str::uuid()->toString(),
            'company_id'      => $company->id,
            'user_id'         => $user->id,
            'department_id'   => $hrDepartment->id,
            'job_title'       => 'HR Manager',
            'base_salary'     => 2000.00,
            'hire_date'       => now()->subYear(),
            'employment_type' => 'full-time',
            'is_active'       => true,
        ]);

        $hrDepartment->update(['manager_id' => $employee->id]);

        // 5. نوع إجازة افتراضي لتسهيل تجارب مسار الإجازات
        $leaveType = LeaveType::create([
            'id'               => Str::uuid()->toString(),
            'company_id'       => $company->id,
            'name'             => 'Annual Leave',
            'allocation_value' => 21,
            'allocation_unit'  => 'days',
            'requires_proof'   => false,
            'is_active'        => true,
        ]);

        // 6. توليد Bearer Token لـ Swagger / Postman
        $token = $user->createToken('test-hr-token')->plainTextToken;

        $this->command->info('==================================================');
        $this->command->info('✅ Test HR Manager Seeded Successfully!');
        $this->command->info('--------------------------------------------------');
        $this->command->info('Company ID   : ' . $company->id);
        $this->command->info('Email        : ' . $uniqueEmail);
        $this->command->info('Password     : password123');
        $this->command->info('Leave Type ID: ' . $leaveType->id);
        $this->command->info('Bearer Token : ' . $token);
        $this->command->info('==================================================');
    }
}
