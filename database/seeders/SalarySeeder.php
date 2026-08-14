<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\SalaryAdvancePolicy;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Services\SalaryService;
use Illuminate\Database\Seeder;

class SalarySeeder extends Seeder
{
    public function __construct(
        private readonly SalaryService $salaryService
    ) {
    }

    public function run(): void
    {
        $companies = Company::all();

        if ($companies->isEmpty()) {
            $this->command->warn('No companies found. Please run Company/Employee seeders first.');
            return;
        }

        foreach ($companies as $company) {
            // 1. جلب مستخدم حقيقي تابع للشركة لاستخدامه كـ creator للحركات المالية
            $adminUser = User::where('company_id', $company->id)->first();

            // في حال عدم وجود مستخدم مرتبط بالشركة مباشرة، نجلب أول مستخدم في النظام
            if (! $adminUser) {
                $adminUser = User::first();
            }

            if (! $adminUser) {
                $this->command->warn("No users found to set as created_by for company ID: {$company->id}");
                continue;
            }

            // 2. إنشاء أو تحديث سياسة السلف للشركة
            SalaryAdvancePolicy::firstOrCreate(
                ['company_id' => $company->id],
                [
                    'max_advance_percentage' => 50.00,
                    'max_repayment_months' => 12,
                    'allow_multiple_active_advances' => false,
                ]
            );

            // 3. جلب موظفي الشركة الفعالين
            $employees = Employee::where('company_id', $company->id)
                ->where('is_active', true)
                ->get();

            if ($employees->isEmpty()) {
                continue;
            }

            // 4. الأشهر المراد تعبئتها
            $monthsToSeed = [
                ['year' => 2026, 'month' => 6, 'status' => SalaryRecord::STATUS_PAID],
                ['year' => 2026, 'month' => 7, 'status' => SalaryRecord::STATUS_PAID],
                ['year' => 2026, 'month' => 8, 'status' => SalaryRecord::STATUS_DRAFT],
            ];

            foreach ($employees as $employee) {
                if (! $employee->base_salary || $employee->base_salary <= 0) {
                    $employee->update(['base_salary' => rand(1500, 4500)]);
                }

                foreach ($monthsToSeed as $period) {
                    $year = $period['year'];
                    $month = $period['month'];
                    $targetStatus = $period['status'];

                    // إنشاء المسودة
                    $record = $this->salaryService->ensureDraftRecord($employee, $month, $year);

                    $record->update([
                        'overtime_amount' => rand(0, 1) ? rand(50, 200) : 0,
                        'late_deduction' => rand(0, 1) ? rand(20, 80) : 0,
                        'absent_deduction' => rand(0, 1) ? rand(50, 150) : 0,
                    ]);

                    // إضافة إضافة مالية (Addition) باستخدام ID المستخدم
                    if (rand(0, 1)) {
                        $this->salaryService->addAdjustment(
                            $record,
                            [
                                'type' => 'addition',
                                'amount' => rand(50, 300),
                                'reason' => 'Spot Bonus / Project delivery reward',
                            ],
                            $adminUser->id // 👈 تمرير User ID الصحيح هنا
                        );
                    }

                    // إضافة خصم مالي (Deduction) باستخدام ID المستخدم
                    if (rand(0, 1)) {
                        $this->salaryService->addAdjustment(
                            $record,
                            [
                                'type' => 'deduction',
                                'amount' => rand(20, 100),
                                'reason' => 'Equipment damage penalty / Manual adjustment',
                            ],
                            $adminUser->id // 👈 تمرير User ID الصحيح هنا
                        );
                    }

                    // إغلاق الراتب إذا كان من المفترض أن يكون مدفوعاً
                    if ($targetStatus === SalaryRecord::STATUS_PAID) {
                        $this->salaryService->markPaid($record, $adminUser->id);
                    }
                }
            }
        }

        $this->command->info('Salary records and adjustments seeded successfully!');
    }
}
