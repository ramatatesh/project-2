<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationAnswer;
use App\Models\EvaluationCycle;
use App\Models\EvaluationPolicy;
use App\Models\EvaluationReview;
use App\Models\EvaluationScore;
use App\Models\EvaluationTemplate;
use App\Models\EvaluationTemplateQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluationSystemTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $hrManager;

    private User $generalManager;

    private Employee $managerEmployee;

    private User $managerUser;

    private Employee $employee;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Eval Co',
            'email' => 'eval@company.test',
            'address' => 'Address',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $this->managerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Manager User',
            'email' => 'manager@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->managerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->managerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'employee_code' => 'EMP-M',
            'job_title' => 'Engineering Manager',
            'base_salary' => 3000,
            'hire_date' => '2020-01-01',
            'employment_type' => 'full-time',
            'is_active' => false,
        ]);

        $this->department->update(['manager_id' => $this->managerEmployee->id]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee@company.test',
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
            'employee_code' => 'EMP-001',
            'job_title' => 'Developer',
            'base_salary' => 1500,
            'hire_date' => '2022-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
    }

    public function test_template_can_be_created_with_questions(): void
    {
        $this->actingAs($this->hrManager);

        $response = $this->postJson('/api/hr/evaluation-templates', [
            'name' => 'Q2 Review',
            'description' => 'Quarterly review',
            'questions' => [
                ['question' => 'Quality of work', 'response_type' => 'rating'],
                ['question' => 'Comments', 'response_type' => 'text'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Q2 Review')
            ->assertJsonCount(2, 'data.questions');

        $this->assertDatabaseHas('evaluation_templates', ['name' => 'Q2 Review', 'company_id' => $this->company->id]);
    }

    public function test_template_can_be_duplicated_from_archive(): void
    {
        $template = $this->createTemplate('Archived Template');
        $this->actingAs($this->hrManager);

        $response = $this->postJson("/api/hr/evaluation-templates/{$template->id}/duplicate", [
            'name' => 'Imported Template',
            'archive_source' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Imported Template');

        $this->assertDatabaseHas('evaluation_templates', ['name' => 'Imported Template', 'is_archived' => false]);
    }

    public function test_cycle_can_be_launched_and_creates_reviews(): void
    {
        $template = $this->createTemplate('Launch Template');
        $this->setPolicy(50, 30, 20, 2);

        $this->actingAs($this->hrManager);

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

        $response = $this->postJson("/api/hr/evaluation-cycles/{$cycle->id}/launch");
        $response->assertOk()->assertJsonPath('data.created_reviews', 2);

        $this->assertDatabaseHas('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
            'reviewer_id' => $this->employeeUser->id,
            'review_type' => EvaluationReview::TYPE_SELF,
        ]);

        $this->assertDatabaseHas('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
            'reviewer_id' => $this->managerUser->id,
            'review_type' => EvaluationReview::TYPE_MANAGER,
        ]);
    }

    public function test_reviewer_can_submit_answers_and_score_is_calculated(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();
        $this->setPolicy(50, 30, 20, 0);

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $managerReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_MANAGER)
            ->first();

        $questionIds = $template->questions->pluck('id')->toArray();

        $this->actingAs($this->employeeUser)
            ->postJson("/api/evaluations/my-reviews/{$selfReview->id}/submit", [
                'answers' => [
                    ['question_id' => $questionIds[0], 'rating' => 4],
                    ['question_id' => $questionIds[1], 'comment' => 'Good'],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->managerUser)
            ->postJson("/api/evaluations/my-reviews/{$managerReview->id}/submit", [
                'answers' => [
                    ['question_id' => $questionIds[0], 'rating' => 4],
                    ['question_id' => $questionIds[1], 'comment' => 'Manager note'],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [
                    ['answer_id' => $this->getAnswerId($selfReview->id, $questionIds[0]), 'hr_score' => 8],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$managerReview->id}/score", [
                'scores' => [
                    ['answer_id' => $this->getAnswerId($managerReview->id, $questionIds[0]), 'hr_score' => 10],
                ],
            ])
            ->assertOk();

        $score = EvaluationScore::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->first();

        $this->assertNotNull($score);
        $this->assertEquals(10.0, $score->manager_score);
        $this->assertEquals(8.0, $score->self_score);
        $this->assertEquals(9.25, $score->final_score);
    }

    public function test_closed_cycle_blocks_submission(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();
        $cycle->update(['status' => EvaluationCycle::STATUS_CLOSED, 'updated_at' => now()]);

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $questionId = $template->questions->first()->id;

        $response = $this->actingAs($this->employeeUser)
            ->postJson("/api/evaluations/my-reviews/{$selfReview->id}/submit", [
                'answers' => [
                    ['question_id' => $questionId, 'rating' => 3],
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_progress_endpoint_returns_metrics(): void
    {
        [$cycle] = $this->createLaunchedCycle();

        $this->actingAs($this->hrManager);

        $this->getJson("/api/hr/evaluation-cycles/{$cycle->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.0.assigned_reviews', 2)
            ->assertJsonPath('data.0.completed_reviews', 0)
            ->assertJsonPath('data.0.status', 'Pending');
    }

    private function createTemplate(string $name): EvaluationTemplate
    {
        $template = EvaluationTemplate::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'is_active' => true,
            'is_archived' => false,
        ]);

        EvaluationTemplateQuestion::create([
            'evaluation_template_id' => $template->id,
            'question' => 'Performance rating',
            'response_type' => EvaluationTemplateQuestion::RESPONSE_TYPE_RATING,
            'sort_order' => 0,
        ]);

        EvaluationTemplateQuestion::create([
            'evaluation_template_id' => $template->id,
            'question' => 'Comments',
            'response_type' => EvaluationTemplateQuestion::RESPONSE_TYPE_TEXT,
            'sort_order' => 1,
        ]);

        return $template->load('questions');
    }

    private function setPolicy(float $manager, float $self, float $peer, int $peerCount): void
    {
        EvaluationPolicy::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'manager_weight' => $manager,
                'self_weight' => $self,
                'peer_weight' => $peer,
                'peer_reviews_count' => $peerCount,
                'apply_review_to_salary' => false,
            ]
        );
    }

    private function createLaunchedCycle(): array
    {
        $this->setPolicy(50, 30, 20, 0);
        $template = $this->createTemplate('Active Template');

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Launched Cycle',
            'start_date' => now()->subDay(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_DRAFT,
            'updated_at' => now(),
        ]);

        app(\App\Services\EvaluationService::class)->launchCycle($cycle);

        return [$cycle, $template];
    }

    private function getAnswerId(string $reviewId, string $questionId): string
    {
        return EvaluationAnswer::where('evaluation_review_id', $reviewId)
            ->where('evaluation_template_question_id', $questionId)
            ->first()
            ->id;
    }
}
