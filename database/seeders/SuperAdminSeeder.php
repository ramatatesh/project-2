<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Company;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    /**
     * يُنشئ الشركة المالكة للمنصة (خبرات) وحساب الـ Super Admin
     * بالإضافة إلى باقات اشتراك افتراضية لتمكين تدفق تسجيل الشركات.
     *
     * يجب تغيير كلمة المرور الافتراضية فورًا عبر متغير البيئة SUPER_ADMIN_PASSWORD
     * أو بعد أول تسجيل دخول.
     */
    public function run(): void
    {
        $company = Company::firstOrCreate(
            ['email' => 'owner@khibrat.sa'],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Khibrat (Platform Owner)',
                'address' => 'HQ',
                'phone' => null,
                'email' => 'owner@khibrat.sa',
                'payroll_currency' => 'SYP',
                'status' => 'active',
            ]
        );

        User::firstOrCreate(
            ['email' => 'superadmin@khibrat.sa'],
            [
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'full_name' => 'Khibrat Super Admin',
                'password_hash' => Hash::make(env('SUPER_ADMIN_PASSWORD', 'Khibrat@2026')),
                'role' => Role::SuperAdmin->value,
                'status' => 'active',
                'is_first_login' => false,
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['name' => 'Free'],
            [
                'id' => Str::uuid()->toString(),
                'plan_type' => 'free',
                'billing_period' => 'month',
                'max_employees' => 10,
                'price' => 0,
                'is_active' => true,
                'max_uses_per_company' => 1,
                'description' => 'Free plan (up to 10 employees).',
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['name' => 'Standard'],
            [
                'id' => Str::uuid()->toString(),
                'plan_type' => 'paid',
                'billing_period' => 'month',
                'max_employees' => 100,
                'price' => 49.99,
                'is_active' => true,
                'max_uses_per_company' => 1,
                'description' => 'Standard monthly plan (up to 100 employees).',
            ]
        );
    }
}
