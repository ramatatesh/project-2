<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
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

        // 3. إنشاء حساب مستخدم
        $user = User::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'full_name'     => 'Rama Tatesh',
            'email'         => $uniqueEmail,
            'password_hash' => bcrypt('password123'),
            'role'          => Role::Employee->value ?? 'employee',
        ]);

        // 4. إنشاء ملف الموظف
        $employee = Employee::create([
            'id'            => Str::uuid()->toString(),
            'company_id'    => $company->id,
            'user_id'       => $user->id,
            'department_id' => $department->id,
            'job_title'     => 'Software Engineer',
            'base_salary'   => 1200.00,
            'hire_date'     => now()->subMonths(6),
        ]);

        // 5. إنشاء نوع إجازة بالحقول الإجبارية المطابقة للميجريشن
        $leaveType = LeaveType::create([
            'id'               => Str::uuid()->toString(),
            'company_id'       => $company->id,
            'name'             => 'Annual Leave',
            'allocation_value' => 30,       // 👈 حقل إجباري
            'allocation_unit'  => 'days',   // 👈 حقل إجباري
        ]);

        // 6. توليد Bearer Token لـ Swagger
        $token = $user->createToken('test-token')->plainTextToken;

        $this->command->info('==================================================');
        $this->command->info('✅ Test Data Seeded Successfully!');
        $this->command->info('--------------------------------------------------');
        $this->command->info('Leave Type ID: ' . $leaveType->id);
        $this->command->info('Bearer Token : ' . $token);
        $this->command->info('==================================================');
    }
}
