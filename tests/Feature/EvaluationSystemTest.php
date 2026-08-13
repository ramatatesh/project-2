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
        // Hardcoded weights: manager 60 + self 10 (no peer) => (10*60 + 8*10) / 70
        $this->assertEquals(9.71, $score->final_score);
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

    public function test_scores_cannot_be_edited_after_cycle_is_closed(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $questionIds = $template->questions->pluck('id')->toArray();

        $this->actingAs($this->employeeUser)
            ->postJson("/api/evaluations/my-reviews/{$selfReview->id}/submit", [
                'answers' => [
                    ['question_id' => $questionIds[0], 'rating' => 4],
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

        $cycle->update(['status' => EvaluationCycle::STATUS_CLOSED, 'updated_at' => now()]);

        $answerId = $this->getAnswerId($selfReview->id, $questionIds[0]);

        $response = $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [
                    ['answer_id' => $answerId, 'hr_score' => 2],
                ],
            ]);

        $response->assertStatus(422);

        // The score entered before closing must not have been overwritten.
        $this->assertEquals(8, EvaluationAnswer::find($answerId)->hr_score);
    }

    public function test_viewing_final_results_does_not_revert_finalized_status(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $managerReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_MANAGER)
            ->first();

        $questionIds = $template->questions->pluck('id')->toArray();

        $this->submitCompletedReview($selfReview, $this->employeeUser, $questionIds, 4, 'Self');
        $this->submitCompletedReview($managerReview, $this->managerUser, $questionIds, 5, 'Manager');

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [
                    ['answer_id' => $this->getAnswerId($selfReview->id, $questionIds[0]), 'hr_score' => 8],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/final-results/{$this->employee->id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', EvaluationScore::STATUS_FINALIZED);

        // Viewing the result (GET, twice) must be a pure read with no side effects.
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->hrManager)
                ->getJson("/api/hr/evaluation-cycles/{$cycle->id}/final-results/{$this->employee->id}")
                ->assertOk()
                ->assertJsonPath('data.status', EvaluationScore::STATUS_FINALIZED);
        }

        $this->assertSame(
            EvaluationScore::STATUS_FINALIZED,
            EvaluationScore::where('evaluation_cycle_id', $cycle->id)
                ->where('employee_id', $this->employee->id)
                ->first()
                ->status
        );
    }

    public function test_viewing_unscored_employee_result_does_not_create_a_row(): void
    {
        [$cycle] = $this->createLaunchedCycle();

        $this->actingAs($this->hrManager)
            ->getJson("/api/hr/evaluation-cycles/{$cycle->id}/final-results/{$this->employee->id}")
            ->assertOk()
            ->assertJsonPath('data.status', EvaluationScore::STATUS_PENDING)
            ->assertJsonPath('data.final_score', null);

        $this->assertDatabaseMissing('evaluation_scores', [
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
        ]);
    }

    public function test_closing_an_already_closed_cycle_returns_409(): void
    {
        [$cycle] = $this->createLaunchedCycle();

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/close")
            ->assertOk();

        $response = $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/close");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Evaluation cycle is already closed.');
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

    public function test_pending_review_becomes_expired_after_due_date(): void
    {
        [$cycle] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $selfReview->update(['due_date' => now()->subDay()->toDateString()]);

        $expired = app(\App\Services\EvaluationService::class)->expirePendingReviews($cycle->id);

        $this->assertSame(1, $expired);
        $this->assertSame(
            EvaluationReview::STATUS_EXPIRED,
            $selfReview->fresh()->status
        );
    }

    public function test_completed_review_is_not_expired_after_due_date(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $questionIds = $template->questions->pluck('id')->toArray();

        $this->actingAs($this->employeeUser)
            ->postJson("/api/evaluations/my-reviews/{$selfReview->id}/submit", [
                'answers' => [
                    ['question_id' => $questionIds[0], 'rating' => 4],
                    ['question_id' => $questionIds[1], 'comment' => 'Done'],
                ],
            ])
            ->assertOk();

        $selfReview->update(['due_date' => now()->subDay()->toDateString()]);

        app(\App\Services\EvaluationService::class)->expirePendingReviews($cycle->id);

        $this->assertSame(
            EvaluationReview::STATUS_COMPLETED,
            $selfReview->fresh()->status
        );
    }

    public function test_expired_review_blocks_submission(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $selfReview->update(['due_date' => now()->subDay()->toDateString()]);

        $questionId = $template->questions->first()->id;

        $response = $this->actingAs($this->employeeUser)
            ->postJson("/api/evaluations/my-reviews/{$selfReview->id}/submit", [
                'answers' => [
                    ['question_id' => $questionId, 'rating' => 3],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertSame(
            EvaluationReview::STATUS_EXPIRED,
            $selfReview->fresh()->status
        );
    }

    public function test_my_reviews_can_filter_expired_status(): void
    {
        [$cycle] = $this->createLaunchedCycle();

        EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('reviewer_id', $this->employeeUser->id)
            ->update(['due_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->employeeUser);

        $this->getJson('/api/evaluations/my-reviews?status=expired')
            ->assertOk()
            ->assertJsonPath('data.0.status', EvaluationReview::STATUS_EXPIRED);
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

    private function submitCompletedReview(
        EvaluationReview $review,
        User $reviewer,
        array $questionIds,
        int $rating,
        string $comment,
    ): void {
        $this->actingAs($reviewer)
            ->postJson("/api/evaluations/my-reviews/{$review->id}/submit", [
                'answers' => [
                    ['question_id' => $questionIds[0], 'rating' => $rating],
                    ['question_id' => $questionIds[1], 'comment' => $comment],
                ],
            ])
            ->assertOk();
    }

    public function test_empty_answers_array_is_rejected(): void
    {
        [$cycle] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $this->actingAs($this->employeeUser)
            ->postJson("/api/evaluations/my-reviews/{$selfReview->id}/submit", [
                'answers' => [],
            ])
            ->assertStatus(422);

        $this->assertSame(EvaluationReview::STATUS_PENDING, $selfReview->fresh()->status);
    }

    public function test_submit_without_rating_answers_is_rejected(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $textQuestionId = $template->questions
            ->firstWhere('response_type', EvaluationTemplateQuestion::RESPONSE_TYPE_TEXT)
            ->id;

        $this->actingAs($this->employeeUser)
            ->postJson("/api/evaluations/my-reviews/{$selfReview->id}/submit", [
                'answers' => [
                    ['question_id' => $textQuestionId, 'comment' => 'Only text'],
                ],
            ])
            ->assertStatus(403);

        $this->assertSame(EvaluationReview::STATUS_PENDING, $selfReview->fresh()->status);
    }

    public function test_cannot_score_pending_review(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        EvaluationAnswer::create([
            'id' => Str::uuid()->toString(),
            'evaluation_review_id' => $selfReview->id,
            'evaluation_template_question_id' => $template->questions->first()->id,
            'rating' => 4,
        ]);

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [
                    ['answer_id' => $this->getAnswerId($selfReview->id, $template->questions->first()->id), 'hr_score' => 8],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot score a review that has not been completed.');
    }

    public function test_cannot_score_text_question(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $questionIds = $template->questions->pluck('id')->toArray();
        $this->submitCompletedReview($selfReview, $this->employeeUser, $questionIds, 4, 'Self');

        $textQuestionId = $template->questions
            ->firstWhere('response_type', EvaluationTemplateQuestion::RESPONSE_TYPE_TEXT)
            ->id;

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [
                    ['answer_id' => $this->getAnswerId($selfReview->id, $textQuestionId), 'hr_score' => 8],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'HR scores can only be applied to rating questions.');
    }

    public function test_empty_scores_array_is_rejected(): void
    {
        [$cycle] = $this->createLaunchedCycle();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [],
            ])
            ->assertStatus(422);
    }

    public function test_cannot_score_after_finalization(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();
        $questionIds = $template->questions->pluck('id')->toArray();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();
        $managerReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_MANAGER)
            ->first();

        $this->submitCompletedReview($selfReview, $this->employeeUser, $questionIds, 4, 'Self');
        $this->submitCompletedReview($managerReview, $this->managerUser, $questionIds, 5, 'Manager');

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [
                    ['answer_id' => $this->getAnswerId($selfReview->id, $questionIds[0]), 'hr_score' => 8],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/final-results/{$this->employee->id}/finalize")
            ->assertOk();

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [
                    ['answer_id' => $this->getAnswerId($selfReview->id, $questionIds[0]), 'hr_score' => 2],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot score a review after the employee result has been finalized.');

        $this->assertSame(
            EvaluationScore::STATUS_FINALIZED,
            EvaluationScore::where('evaluation_cycle_id', $cycle->id)
                ->where('employee_id', $this->employee->id)
                ->value('status')
        );
        $this->assertEquals(8, EvaluationAnswer::find($this->getAnswerId($selfReview->id, $questionIds[0]))->hr_score);
    }

    public function test_cannot_finalize_before_all_reviews_are_completed(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();
        $questionIds = $template->questions->pluck('id')->toArray();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $this->submitCompletedReview($selfReview, $this->employeeUser, $questionIds, 4, 'Self');

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/reviews/{$selfReview->id}/score", [
                'scores' => [
                    ['answer_id' => $this->getAnswerId($selfReview->id, $questionIds[0]), 'hr_score' => 8],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/final-results/{$this->employee->id}/finalize")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot finalize an employee score before all required reviews are completed.');
    }

    public function test_cannot_finalize_without_a_final_score(): void
    {
        [$cycle, $template] = $this->createLaunchedCycle();
        $questionIds = $template->questions->pluck('id')->toArray();

        $selfReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();
        $managerReview = EvaluationReview::where('evaluation_cycle_id', $cycle->id)
            ->where('employee_id', $this->employee->id)
            ->where('review_type', EvaluationReview::TYPE_MANAGER)
            ->first();

        $this->submitCompletedReview($selfReview, $this->employeeUser, $questionIds, 4, 'Self');
        $this->submitCompletedReview($managerReview, $this->managerUser, $questionIds, 5, 'Manager');

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/final-results/{$this->employee->id}/finalize")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot finalize an employee score because no final score is available.');

        $this->assertDatabaseMissing('evaluation_scores', [
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
        ]);
    }

    public function test_cannot_delete_launched_cycle(): void
    {
        [$cycle] = $this->createLaunchedCycle();

        $this->actingAs($this->hrManager)
            ->deleteJson("/api/hr/evaluation-cycles/{$cycle->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete an evaluation cycle after it has started.');

        $this->assertDatabaseHas('evaluation_cycles', ['id' => $cycle->id]);
    }

    public function test_draft_cycle_without_reviews_can_be_deleted(): void
    {
        $template = $this->createTemplate('Draft Template');

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Draft Cycle',
            'start_date' => now()->subDay(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_DRAFT,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->hrManager)
            ->deleteJson("/api/hr/evaluation-cycles/{$cycle->id}")
            ->assertOk();

        $this->assertDatabaseMissing('evaluation_cycles', ['id' => $cycle->id]);
    }
}
