<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationCycle;
use App\Models\EvaluationPolicy;
use App\Models\EvaluationScore;
use App\Models\EvaluationTemplate;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceInstallment;
use App\Models\SalaryAdvancePolicy;
use App\Models\SalaryRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluationSalaryLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hrManager;

    private Employee $employee;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Salary Eval Co',
            'email' => 'salary-eval@company.test',
            'address' => 'Address',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@salary-eval.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee@salary-eval.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
    }

    public function test_disabled_policy_does_not_change_salary(): void
    {
        $this->setPolicy(false, 10, 5, 8);
        $this->createFinalizedScore(8.7);

        $record = $this->generateSalary();

        $this->assertEquals(0, (float) $record->bonus_amount);
        $this->assertEquals(0, (float) $record->manual_deduction);
        $this->assertEquals(1000, (float) $record->net_salary);
        $this->assertEquals(1000, (float) $this->employee->fresh()->base_salary);
    }

    public function test_excellent_score_applies_bonus(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(8.7);

        $record = $this->generateSalary();

        $this->assertEquals(100, (float) $record->bonus_amount);
        $this->assertEquals(0, (float) $record->manual_deduction);
        $this->assertEquals(1100, (float) $record->net_salary);
        $this->assertEquals(1000, (float) $record->base_salary);
        $this->assertEquals(1000, (float) $this->employee->fresh()->base_salary);
    }

    public function test_good_score_applies_bonus(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(6.5);

        $record = $this->generateSalary();

        $this->assertEquals(50, (float) $record->bonus_amount);
        $this->assertEquals(0, (float) $record->manual_deduction);
        $this->assertEquals(1050, (float) $record->net_salary);
    }

    public function test_acceptable_score_applies_nothing(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(5.0);

        $record = $this->generateSalary();

        $this->assertEquals(0, (float) $record->bonus_amount);
        $this->assertEquals(0, (float) $record->manual_deduction);
        $this->assertEquals(1000, (float) $record->net_salary);
    }

    public function test_poor_score_applies_deduction(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(3.2);

        $record = $this->generateSalary();

        $this->assertEquals(0, (float) $record->bonus_amount);
        $this->assertEquals(80, (float) $record->manual_deduction);
        $this->assertEquals(920, (float) $record->net_salary);
        $this->assertEquals(1000, (float) $this->employee->fresh()->base_salary);
    }

    public function test_no_finalized_evaluation_does_not_change_salary(): void
    {
        $this->setPolicy(true, 10, 5, 8);

        $record = $this->generateSalary();

        $this->assertEquals(0, (float) $record->bonus_amount);
        $this->assertEquals(0, (float) $record->manual_deduction);
        $this->assertEquals(1000, (float) $record->net_salary);
    }

    public function test_pending_evaluation_does_not_change_salary(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createScore(9.0, EvaluationScore::STATUS_PENDING, null);

        $record = $this->generateSalary();

        $this->assertEquals(0, (float) $record->bonus_amount);
        $this->assertEquals(1000, (float) $record->net_salary);
    }

    public function test_null_final_score_does_not_change_salary(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createScore(null, EvaluationScore::STATUS_FINALIZED, now());

        $record = $this->generateSalary();

        $this->assertEquals(0, (float) $record->bonus_amount);
        $this->assertEquals(1000, (float) $record->net_salary);
    }

    public function test_latest_finalized_score_is_used(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(3.0, now()->subDays(10));
        $this->createFinalizedScore(9.0, now()->subDay());

        $record = $this->generateSalary();

        $this->assertEquals(100, (float) $record->bonus_amount);
        $this->assertEquals(0, (float) $record->manual_deduction);
        $this->assertEquals(1100, (float) $record->net_salary);
    }

    public function test_regenerate_does_not_stack_evaluation_bonus(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(8.7);

        $this->generateSalary();
        $record = $this->generateSalary();

        $this->assertEquals(100, (float) $record->bonus_amount);
        $this->assertEquals(1100, (float) $record->net_salary);
    }

    public function test_paid_salary_is_not_modified(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(8.7);

        $paid = SalaryRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'month' => 8,
            'year' => 2026,
            'base_salary' => 1000,
            'overtime_amount' => 0,
            'bonus_amount' => 0,
            'late_deduction' => 0,
            'absent_deduction' => 0,
            'loan_deduction' => 0,
            'manual_bonus' => 0,
            'manual_deduction' => 0,
            'net_salary' => 1000,
            'status' => SalaryRecord::STATUS_PAID,
        ]);

        $this->actingAs($this->hrManager)
            ->postJson('/api/management/salaries/generate', [
                'month' => 8,
                'year' => 2026,
                'employee_id' => $this->employee->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.skipped_paid', 1);

        $paid->refresh();
        $this->assertEquals(0, (float) $paid->bonus_amount);
        $this->assertEquals(1000, (float) $paid->net_salary);
        $this->assertSame(SalaryRecord::STATUS_PAID, $paid->status);
    }

    public function test_existing_overtime_absence_late_and_loan_are_preserved(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(8.7);

        SalaryAdvancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'max_advance_percentage' => 50,
            'max_repayment_months' => 12,
            'allow_multiple_active_advances' => false,
        ]);

        $advance = SalaryAdvance::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_amount' => 40,
            'repayment_months' => 1,
            'monthly_installment' => 40,
            'status' => SalaryAdvance::STATUS_APPROVED,
        ]);

        SalaryAdvanceInstallment::create([
            'id' => Str::uuid()->toString(),
            'salary_advance_id' => $advance->id,
            'amount' => 40,
            'due_date' => '2026-08-15',
            'status' => SalaryAdvanceInstallment::STATUS_PENDING,
        ]);

        SalaryRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'month' => 8,
            'year' => 2026,
            'base_salary' => 1000,
            'overtime_amount' => 50,
            'bonus_amount' => 0,
            'late_deduction' => 15,
            'absent_deduction' => 20,
            'loan_deduction' => 0,
            'manual_bonus' => 25,
            'manual_deduction' => 0,
            'net_salary' => 1040,
            'status' => SalaryRecord::STATUS_DRAFT,
        ]);

        $record = $this->generateSalary();

        $this->assertEquals(50, (float) $record->overtime_amount);
        $this->assertEquals(15, (float) $record->late_deduction);
        $this->assertEquals(20, (float) $record->absent_deduction);
        $this->assertEquals(40, (float) $record->loan_deduction);
        $this->assertEquals(25, (float) $record->manual_bonus);
        $this->assertEquals(100, (float) $record->bonus_amount);
        $this->assertEquals(0, (float) $record->manual_deduction);
        $this->assertEquals(1100, (float) $record->net_salary);
        $this->assertEquals(1000, (float) $this->employee->fresh()->base_salary);
    }

    public function test_other_company_evaluation_does_not_apply(): void
    {
        $this->setPolicy(true, 10, 5, 8);

        $otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Co',
            'email' => 'other@company.test',
            'address' => 'Address',
            'phone' => '+963111111',
            'status' => 'active',
        ]);

        $template = EvaluationTemplate::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'name' => 'Other Template',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Other Cycle',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_CLOSED,
            'updated_at' => now(),
        ]);

        EvaluationScore::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
            'final_score' => 10,
            'status' => EvaluationScore::STATUS_FINALIZED,
            'finalized_at' => now(),
        ]);

        $record = $this->generateSalary();

        $this->assertEquals(0, (float) $record->bonus_amount);
        $this->assertEquals(1000, (float) $record->net_salary);
    }

    public function test_salary_api_contract_is_unchanged(): void
    {
        $this->setPolicy(true, 10, 5, 8);
        $this->createFinalizedScore(8.7);
        $record = $this->generateSalary();

        $response = $this->actingAs($this->hrManager)
            ->getJson("/api/management/salaries/{$record->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.base_salary', 1000)
            ->assertJsonPath('data.net_salary', 1100)
            ->assertJsonPath('data.components.bonus_amount', 100)
            ->assertJsonPath('data.components.manual_deduction', 0);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('evaluation_bonus_amount', $data);
        $this->assertArrayNotHasKey('evaluation_deduction_amount', $data);
        $this->assertArrayNotHasKey('evaluation_bonus_amount', $data['components']);
    }

    private function setPolicy(bool $apply, float $excellent, float $good, float $poor): void
    {
        EvaluationPolicy::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'apply_review_to_salary' => $apply,
                'excellent_bonus_percent' => $excellent,
                'good_bonus_percent' => $good,
                'poor_deduction_percent' => $poor,
                'peer_reviews_count' => 0,
            ]
        );
    }

    private function createFinalizedScore(float $finalScore, $finalizedAt = null): EvaluationScore
    {
        return $this->createScore($finalScore, EvaluationScore::STATUS_FINALIZED, $finalizedAt ?? now());
    }

    private function createScore(?float $finalScore, string $status, $finalizedAt): EvaluationScore
    {
        $template = EvaluationTemplate::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Template '.Str::random(6),
            'is_active' => true,
            'is_archived' => false,
        ]);

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Cycle '.Str::random(6),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_CLOSED,
            'updated_at' => now(),
        ]);

        return EvaluationScore::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
            'final_score' => $finalScore,
            'status' => $status,
            'finalized_at' => $finalizedAt,
        ]);
    }

    private function generateSalary(): SalaryRecord
    {
        $this->actingAs($this->hrManager)
            ->postJson('/api/management/salaries/generate', [
                'month' => 8,
                'year' => 2026,
                'employee_id' => $this->employee->id,
            ])
            ->assertCreated();

        return SalaryRecord::where('employee_id', $this->employee->id)
            ->where('month', 8)
            ->where('year', 2026)
            ->firstOrFail();
    }
}
