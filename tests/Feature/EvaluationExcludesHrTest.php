<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationCycle;
use App\Models\EvaluationPolicy;
use App\Models\EvaluationReview;
use App\Models\EvaluationTemplate;
use App\Models\EvaluationTemplateQuestion;
use App\Models\User;
use App\Services\EvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluationExcludesHrTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $generalManager;

    private User $hrManagerUser;

    private Employee $hrManagerEmployee;

    private Department $department;

    private User $employeeUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Eval HR Exclusion Co',
            'email' => 'evalhr@company.test',
            'address' => 'Address',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm@evalhr.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        // The HR Manager also holds a real Employee row (job title, department, salary) -
        // exactly like HrManagerController creates them - so she is a genuine member of the
        // department's employee pool unless explicitly excluded from evaluation assignment.
        $this->hrManagerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@evalhr.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->hrManagerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->hrManagerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'HR Manager',
            'base_salary' => 2000,
            'hire_date' => '2021-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee@evalhr.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1500,
            'hire_date' => '2022-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
    }

    public function test_hr_manager_is_never_a_subject_and_never_picked_as_peer_reviewer(): void
    {
        // The department's only other member besides the regular employee is the HR Manager -
        // if HR exclusion were broken, she would be the ONLY possible peer candidate and would
        // be guaranteed to be picked (peer_reviews_count=1). This makes the test deterministic.
        $this->setPolicy(peerCount: 1);
        $template = $this->createTemplate();

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Cycle 1',
            'start_date' => now()->subDay(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_DRAFT,
            'updated_at' => now(),
        ]);

        $result = app(EvaluationService::class)->launchCycle($cycle);

        // Only the regular employee's self-review + manager-review (GM fallback, since the
        // department has no dedicated manager) - no peer review at all, since the only
        // candidate (HR) is excluded.
        $this->assertSame(2, $result['created_reviews']);

        $this->assertDatabaseHas('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
            'reviewer_id' => $this->employeeUser->id,
            'review_type' => EvaluationReview::TYPE_SELF,
        ]);

        $this->assertDatabaseHas('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
            'reviewer_id' => $this->generalManager->id,
            'review_type' => EvaluationReview::TYPE_MANAGER,
        ]);

        // HR must never be a subject: no review of any type where employee_id is her own.
        $this->assertDatabaseMissing('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->hrManagerEmployee->id,
        ]);

        // HR must never be a reviewer either (peer or otherwise) for anyone.
        $this->assertDatabaseMissing('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'reviewer_id' => $this->hrManagerUser->id,
        ]);
    }

    public function test_hr_manager_set_as_department_manager_is_never_assigned_as_reviewer(): void
    {
        // Edge case: the department's manager_id happens to point at the HR Manager's own
        // employee row. Even then, she must never be handed a manager-review to fill out -
        // it should silently fall back to the General Manager instead.
        $this->department->update(['manager_id' => $this->hrManagerEmployee->id]);

        $this->setPolicy(peerCount: 0);
        $template = $this->createTemplate();

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Cycle 2',
            'start_date' => now()->subDay(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_DRAFT,
            'updated_at' => now(),
        ]);

        app(EvaluationService::class)->launchCycle($cycle);

        $this->assertDatabaseHas('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
            'reviewer_id' => $this->generalManager->id,
            'review_type' => EvaluationReview::TYPE_MANAGER,
        ]);

        $this->assertDatabaseMissing('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'reviewer_id' => $this->hrManagerUser->id,
        ]);
    }

    private function createTemplate(): EvaluationTemplate
    {
        $template = EvaluationTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'HR Exclusion Template',
            'is_active' => true,
            'is_archived' => false,
        ]);

        EvaluationTemplateQuestion::create([
            'evaluation_template_id' => $template->id,
            'question' => 'Performance rating',
            'response_type' => EvaluationTemplateQuestion::RESPONSE_TYPE_RATING,
            'sort_order' => 0,
        ]);

        return $template->load('questions');
    }

    private function setPolicy(int $peerCount): void
    {
        EvaluationPolicy::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'apply_review_to_salary' => false,
                'peer_reviews_count' => $peerCount,
            ]
        );
    }
}
