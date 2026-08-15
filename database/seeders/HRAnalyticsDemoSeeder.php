<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationCycle;
use App\Models\EvaluationScore;
use App\Models\EvaluationTemplate;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SalaryRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dedicated demo/test data for the 5 HR Analytics endpoints (analytics/hr/*):
 * turnover-rate, demographics, department-budgets, daily-verification-rate,
 * realtime-headcount, performance-distribution.
 *
 * Isolated lab company - safe to re-run (wipes and regenerates only its own data).
 *
 * Run:
 *   php artisan db:seed --class=HRAnalyticsDemoSeeder
 */
class HRAnalyticsDemoSeeder extends Seeder
{
    private const COMPANY_EMAIL = 'hr-analytics-lab@khibrat.analytics.test';

    private const DOMAIN = 'khibrat.analytics.test';

    private const PASSWORD = 'password123';

    private const DEPARTMENTS = ['Engineering', 'Sales', 'Finance', 'Human Resources'];

    /** Base salary range per department, to give department-budgets visible variation. */
    private const SALARY_RANGES = [
        'Engineering' => [1800, 2600],
        'Sales' => [1000, 1700],
        'Finance' => [1500, 2200],
        'Human Resources' => [1100, 1800],
    ];

    private const ACTIVE_EMPLOYEES_PER_DEPARTMENT = 15;

    private const DEPARTED_PER_QUARTER = 3;

    private const ATTENDANCE_DAYS = 14;

    private Company $company;

    /** @var array<string, Department> */
    private array $departments = [];

    private User $hrManager;

    private User $generalManager;

    /** @var Employee[] */
    private array $activeEmployees = [];

    public function run(): void
    {
        DB::transaction(function () {
            $this->bootstrapCompanyAndStaff();
            $this->resetPreviousLabData();
            $this->createEmployees();
            $this->createAttendanceData();
            $this->createLeaveData();
            $this->createSalaryData();
            $this->createEvaluationData();
        });

        $this->printSummary();
    }

    private function bootstrapCompanyAndStaff(): void
    {
        $this->company = Company::firstOrCreate(
            ['email' => self::COMPANY_EMAIL],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Khibrat Analytics Lab',
                'address' => 'Damascus, Analytics Lab',
                'phone' => '+963911000002',
                'domain' => 'khibrat-analytics-lab.dev',
                'payroll_currency' => 'SYP',
                'status' => 'active',
                'tagline' => 'HR analytics test company',
                'about' => 'Isolated company used only by HRAnalyticsDemoSeeder.',
            ]
        );

        $this->hrManager = $this->upsertUser('hr@'.self::DOMAIN, 'Analytics Lab HR', Role::HrManager->value);
        $this->generalManager = $this->upsertUser('gm@'.self::DOMAIN, 'Analytics Lab GM', Role::GeneralManager->value);

        foreach (self::DEPARTMENTS as $name) {
            $this->departments[$name] = Department::firstOrCreate(
                ['company_id' => $this->company->id, 'name' => $name],
                ['id' => Str::uuid()->toString(), 'is_active' => true]
            );
        }
    }

    /**
     * Wipe only this lab company's generated data (employees/attendance/leave/salary/
     * evaluation), so re-running the seeder never piles up duplicate rows. Company,
     * departments and the two staff logins are kept (stable identity via firstOrCreate).
     */
    private function resetPreviousLabData(): void
    {
        $companyId = $this->company->id;

        $employeeIds = Employee::where('company_id', $companyId)->pluck('id');

        if ($employeeIds->isNotEmpty()) {
            EvaluationScore::where('company_id', $companyId)->delete();
            SalaryRecord::where('company_id', $companyId)->delete();
            LeaveRequest::where('company_id', $companyId)->delete();

            $recordIds = AttendanceRecord::where('company_id', $companyId)->pluck('id');
            if ($recordIds->isNotEmpty()) {
                AttendanceAdjustment::whereIn('attendance_record_id', $recordIds)->delete();
            }
            AttendanceRecord::where('company_id', $companyId)->delete();

            $userIds = Employee::whereIn('id', $employeeIds)->pluck('user_id');
            Employee::whereIn('id', $employeeIds)->delete();
            User::whereIn('id', $userIds)
                ->whereNotIn('id', [$this->hrManager->id, $this->generalManager->id])
                ->delete();
        }

        EvaluationCycle::where('company_id', $companyId)->delete();
        EvaluationTemplate::where('company_id', $companyId)->delete();
        LeaveType::where('company_id', $companyId)->delete();
    }

    /**
     * Active employees: spread across departments, genders, and age bands (demographics),
     * with hire_date well before "today" so quarterly turnover has a real active baseline.
     * Departed employees: is_active=false with created_at explicitly backdated into each
     * of the last 4 calendar quarters (turnover-rate reads created_at as the departure marker).
     */
    private function createEmployees(): void
    {
        $ageBands = [
            fn () => Carbon::now()->subYears(rand(19, 24))->subDays(rand(0, 300)), // under_25
            fn () => Carbon::now()->subYears(rand(25, 34))->subDays(rand(0, 300)), // 25_34
            fn () => Carbon::now()->subYears(rand(35, 44))->subDays(rand(0, 300)), // 35_44
            fn () => Carbon::now()->subYears(rand(45, 54))->subDays(rand(0, 300)), // 45_54
            fn () => Carbon::now()->subYears(rand(55, 64))->subDays(rand(0, 300)), // 55_plus
        ];

        $counter = 0;
        $genderCycle = ['male', 'female', 'male', 'female', null]; // 1 in 5 unspecified

        foreach (self::DEPARTMENTS as $deptName) {
            [$minSalary, $maxSalary] = self::SALARY_RANGES[$deptName];

            for ($i = 0; $i < self::ACTIVE_EMPLOYEES_PER_DEPARTMENT; $i++) {
                $counter++;
                $ageBand = $ageBands[$counter % 5];
                $gender = $genderCycle[$counter % 5];

                $user = User::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $this->company->id,
                    'full_name' => "{$deptName} Staff {$counter}",
                    'email' => "staff{$counter}@".self::DOMAIN,
                    'password_hash' => bcrypt(self::PASSWORD),
                    'role' => Role::Employee->value,
                    'status' => 'active',
                    'is_first_login' => false,
                    'gender' => $gender,
                    'birth_date' => $ageBand()->toDateString(),
                ]);

                $employee = Employee::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'company_id' => $this->company->id,
                    'department_id' => $this->departments[$deptName]->id,
                    'job_title' => $deptName.' Specialist',
                    'base_salary' => rand($minSalary, $maxSalary),
                    'hire_date' => Carbon::now()->subYears(rand(1, 4))->subDays(rand(0, 300))->toDateString(),
                    'employment_type' => 'full-time',
                    'is_active' => true,
                ]);

                $this->activeEmployees[] = $employee;
            }
        }

        $this->createDepartedEmployees();
    }

    /** 3 departed employees per quarter of the current year -> visible turnover every quarter. */
    private function createDepartedEmployees(): void
    {
        $year = (int) Carbon::now()->year;

        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $startMonth = ($quarter - 1) * 3 + 1;
            $quarterStart = Carbon::create($year, $startMonth, 1)->startOfMonth();
            $quarterEnd = $quarterStart->copy()->addMonths(2)->endOfMonth();

            if ($quarterStart->isFuture()) {
                continue;
            }

            $departureCap = $quarterEnd->isFuture() ? Carbon::now() : $quarterEnd;

            for ($i = 0; $i < self::DEPARTED_PER_QUARTER; $i++) {
                $deptName = self::DEPARTMENTS[$i % count(self::DEPARTMENTS)];
                $departedAt = $quarterStart->copy()->addDays(rand(0, max(0, $quarterStart->diffInDays($departureCap))));

                $user = User::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $this->company->id,
                    'full_name' => "Departed Q{$quarter} {$i}",
                    'email' => "departed-q{$quarter}-{$i}@".self::DOMAIN,
                    'password_hash' => bcrypt(self::PASSWORD),
                    'role' => Role::Employee->value,
                    'status' => 'inactive',
                    'is_first_login' => false,
                    'gender' => $i % 2 === 0 ? 'male' : 'female',
                    'birth_date' => Carbon::now()->subYears(rand(26, 45))->toDateString(),
                ]);

                $employee = Employee::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'company_id' => $this->company->id,
                    'department_id' => $this->departments[$deptName]->id,
                    'job_title' => $deptName.' Specialist',
                    'base_salary' => rand(1000, 2000),
                    'hire_date' => $quarterStart->copy()->subYears(rand(1, 3))->toDateString(),
                    'employment_type' => 'full-time',
                    'is_active' => false,
                ]);

                // employees.created_at is not mass-assignable; patch it directly so the
                // "departure" timestamp lands inside this specific quarter.
                DB::table('employees')->where('id', $employee->id)->update(['created_at' => $departedAt]);
            }
        }
    }

    /**
     * Last 14 days of attendance for every active employee: a realistic mix of
     * digital (QR/GPS) vs manual verification, some lateness today, and some
     * still-checked-in-now records (for realtime-headcount).
     */
    private function createAttendanceData(): void
    {
        $today = Carbon::today();
        $recordIndex = 0;

        foreach ($this->activeEmployees as $employee) {
            for ($daysAgo = self::ATTENDANCE_DAYS - 1; $daysAgo >= 0; $daysAgo--) {
                $workDate = $today->copy()->subDays($daysAgo);

                $recordIndex++;
                $isToday = $workDate->isSameDay($today);
                $isDigital = rand(1, 100) <= 80; // ~80% digital, ~20% manual
                $isLate = rand(1, 100) <= 15;
                $stillCheckedIn = $isToday && rand(1, 100) <= 40;

                $checkInTime = $workDate->copy()->setTime(9, $isLate ? rand(16, 45) : rand(0, 10));
                $lateMinutes = $isLate ? abs($checkInTime->diffInMinutes($workDate->copy()->setTime(9, 0))) : 0;

                $record = AttendanceRecord::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $this->company->id,
                    'employee_id' => $employee->id,
                    'work_date' => $workDate->toDateString(),
                    'check_in_time' => $checkInTime,
                    'check_out_time' => $stillCheckedIn ? null : $workDate->copy()->setTime(17, rand(0, 30)),
                    'check_in_lat' => $isDigital ? 33.5138 : null,
                    'check_in_lng' => $isDigital ? 36.2765 : null,
                    'check_in_device_id' => $isDigital ? 'seed-device-'.$recordIndex : null,
                    'qr_token_used' => $isDigital ? Str::random(20) : null,
                    'late_minutes' => $lateMinutes,
                    'early_leave_minutes' => 0,
                    'total_work_minutes' => $stillCheckedIn ? null : 480,
                    'status' => $stillCheckedIn ? AttendanceRecord::STATUS_CHECKED_IN : AttendanceRecord::STATUS_COMPLETED,
                    'attendance_type' => $isLate ? AttendanceRecord::TYPE_LATE : AttendanceRecord::TYPE_PRESENT,
                ]);

                // ~10% of records: force into the "manual" bucket via an adjustment,
                // even if it has digital markers - exercises that branch of the analytics query.
                if (rand(1, 100) <= 10) {
                    AttendanceAdjustment::create([
                        'id' => Str::uuid()->toString(),
                        'company_id' => $this->company->id,
                        'attendance_record_id' => $record->id,
                        'adjusted_by' => $this->hrManager->id,
                        'old_check_in' => $checkInTime,
                        'new_check_in' => $checkInTime,
                        'reason' => 'Seeded manual verification correction.',
                    ]);
                }
            }
        }
    }

    /** A handful of approved leave requests spanning today, for realtime-headcount. */
    private function createLeaveData(): void
    {
        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 21,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $onLeaveToday = array_slice($this->activeEmployees, 0, 5);

        foreach ($onLeaveToday as $employee) {
            LeaveRequest::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => Carbon::today()->subDays(1)->toDateString(),
                'end_date' => Carbon::today()->addDays(2)->toDateString(),
                'requested_value' => 4,
                'status' => 'approved',
                'reviewed_by' => $this->hrManager->id,
                'reviewed_at' => now(),
            ]);
        }
    }

    /** 12 months of the current year, all active employees - powers department-budgets. */
    private function createSalaryData(): void
    {
        $year = (int) Carbon::now()->year;

        foreach ($this->activeEmployees as $employee) {
            for ($month = 1; $month <= 12; $month++) {
                $overtime = rand(0, 150);
                $bonus = rand(0, 100);
                $lateDeduction = rand(0, 40);
                $absentDeduction = rand(0, 60);
                $base = (float) $employee->base_salary;
                $net = $base + $overtime + $bonus - $lateDeduction - $absentDeduction;

                SalaryRecord::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $this->company->id,
                    'employee_id' => $employee->id,
                    'month' => $month,
                    'year' => $year,
                    'base_salary' => $base,
                    'overtime_amount' => $overtime,
                    'bonus_amount' => $bonus,
                    'late_deduction' => $lateDeduction,
                    'absent_deduction' => $absentDeduction,
                    'loan_deduction' => 0,
                    'manual_bonus' => 0,
                    'manual_deduction' => 0,
                    'net_salary' => round($net, 2),
                    'status' => 'paid',
                ]);
            }
        }
    }

    /** One cycle this year, scores spread across all 4 performance bands. */
    private function createEvaluationData(): void
    {
        $template = EvaluationTemplate::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Analytics Lab Template',
            'description' => 'Template used by HRAnalyticsDemoSeeder.',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Analytics Lab — '.Carbon::now()->year.' Cycle',
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date' => Carbon::now()->endOfYear()->toDateString(),
            'status' => EvaluationCycle::STATUS_CLOSED,
            'updated_at' => now(),
        ]);

        // 25% excellent, 40% good, 25% acceptable, 10% weak.
        $bandRanges = [
            fn () => rand(9000, 9900) / 100,
            fn () => rand(7500, 8899) / 100,
            fn () => rand(6000, 7499) / 100,
            fn () => rand(3000, 5999) / 100,
        ];
        $weights = [25, 40, 25, 10];

        foreach ($this->activeEmployees as $employee) {
            $roll = rand(0, 99);
            $cumulative = 0;
            $band = 3;
            foreach ($weights as $bandIndex => $weight) {
                $cumulative += $weight;
                if ($roll < $cumulative) {
                    $band = $bandIndex;
                    break;
                }
            }

            EvaluationScore::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $this->company->id,
                'evaluation_cycle_id' => $cycle->id,
                'employee_id' => $employee->id,
                'final_score' => $bandRanges[$band](),
                'status' => EvaluationScore::STATUS_FINALIZED,
                'finalized_by' => $this->hrManager->id,
                'finalized_at' => now(),
            ]);
        }
    }

    private function upsertUser(string $email, string $fullName, string $role): User
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'company_id' => $this->company->id,
                'full_name' => $fullName,
                'password_hash' => bcrypt(self::PASSWORD),
                'role' => $role,
                'status' => 'active',
                'is_first_login' => false,
            ]);

            return $user->fresh();
        }

        return User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => bcrypt(self::PASSWORD),
            'role' => $role,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    private function printSummary(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info('==================================================');
        $this->command->info('HRAnalyticsDemoSeeder ready');
        $this->command->info('Company: '.$this->company->name.' ('.$this->company->id.')');
        $this->command->info('Active employees seeded: '.count($this->activeEmployees));
        $this->command->info('--------------------------------------------------');
        $this->command->info('Logins (password: '.self::PASSWORD.')');
        $this->command->info('  HR Manager:      hr@'.self::DOMAIN);
        $this->command->info('  General Manager: gm@'.self::DOMAIN);
        $this->command->info('--------------------------------------------------');
        $this->command->info('GET /api/analytics/hr/turnover-rate');
        $this->command->info('GET /api/analytics/hr/demographics');
        $this->command->info('GET /api/analytics/hr/department-budgets');
        $this->command->info('GET /api/analytics/hr/daily-verification-rate');
        $this->command->info('GET /api/analytics/hr/realtime-headcount');
        $this->command->info('GET /api/analytics/hr/performance-distribution');
        $this->command->info('==================================================');
    }
}
