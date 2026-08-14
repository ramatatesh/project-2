<?php

namespace Database\Seeders;

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
use App\Services\EvaluationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotent test data for the existing Performance Evaluation workflow.
 *
 * Does NOT change evaluation APIs/workflow. Creates a dedicated lab company and
 * scenario employees so Flutter / Swagger / Employee AI Assistant can be tested.
 *
 * Run:
 *   php artisan db:seed --class=PerformanceEvaluationTestSeeder
 */
class PerformanceEvaluationTestSeeder extends Seeder
{
    private const COMPANY_EMAIL = 'evaluation-lab@khibrat.perf.test';

    private const PASSWORD = 'password123';

    private const DOMAIN = 'khibrat.perf.test';

    /** @var array<string, array{email: string, name: string, scenario: string}> */
    private const SCENARIOS = [
        'new' => [
            'email' => 'evaluation-new@khibrat.perf.test',
            'name' => 'Evaluation Test - New',
            'scenario' => 'Pending self review, not started (no answers)',
        ],
        'incomplete' => [
            'email' => 'evaluation-incomplete@khibrat.perf.test',
            'name' => 'Evaluation Test - Incomplete',
            'scenario' => 'Pending self review with partial draft answers (not submitted)',
        ],
        'completed' => [
            'email' => 'evaluation-completed@khibrat.perf.test',
            'name' => 'Evaluation Test - Completed',
            'scenario' => 'Self review completed; manager review still pending',
        ],
        'review' => [
            'email' => 'evaluation-review@khibrat.perf.test',
            'name' => 'Evaluation Test - HR Review',
            'scenario' => 'Self+manager completed; ready for HR scoring (pending score)',
        ],
        'finalized' => [
            'email' => 'evaluation-finalized@khibrat.perf.test',
            'name' => 'Evaluation Test - Finalized',
            'scenario' => 'All reviews scored and final score finalized',
        ],
        'none' => [
            'email' => 'evaluation-none@khibrat.perf.test',
            'name' => 'Evaluation Test - No Evaluation',
            'scenario' => 'Active employee with no reviews in any cycle',
        ],
        'multiple' => [
            'email' => 'evaluation-multiple@khibrat.perf.test',
            'name' => 'Evaluation Test - Multiple Evaluations',
            'scenario' => 'Past finalized cycle + current incomplete self review',
        ],
        'expired' => [
            'email' => 'evaluation-expired@khibrat.perf.test',
            'name' => 'Evaluation Test - Expired',
            'scenario' => 'Pending self review past due_date (status=expired)',
        ],
        'mixed' => [
            'email' => 'evaluation-mixed@khibrat.perf.test',
            'name' => 'Evaluation Test - Mixed Forms',
            'scenario' => 'Self completed, peer completed, manager still pending',
        ],
    ];

    private Company $company;

    private Department $department;

    private User $hrUser;

    private User $managerUser;

    private Employee $managerEmployee;

    private User $peerUser;

    private Employee $peerEmployee;

    /** @var array<string, array{user: User, employee: Employee}> */
    private array $scenarioPeople = [];

    private EvaluationTemplate $template;

    private EvaluationTemplateQuestion $ratingQuestion;

    private EvaluationTemplateQuestion $textQuestion;

    private EvaluationCycle $activeCycle;

    private EvaluationCycle $pastCycle;

    private EvaluationCycle $expiredCycle;

    private EvaluationService $evaluationService;

    public function run(): void
    {
        $this->evaluationService = app(EvaluationService::class);

        DB::transaction(function () {
            $this->bootstrapCompanyTeam();
            $this->resetEvaluationArtifacts();
            $this->createTemplateAndPolicy();
            $this->createCycles();
            $this->seedScenarios();
        });

        $this->printCredentials();
    }

    private function bootstrapCompanyTeam(): void
    {
        $this->company = Company::firstOrCreate(
            ['email' => self::COMPANY_EMAIL],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Khibrat Evaluation Lab',
                'address' => 'Damascus, Evaluation Lab',
                'phone' => '+963911000001',
                'domain' => 'khibrat-eval-lab.dev',
                'payroll_currency' => 'SYP',
                'status' => 'active',
                'tagline' => 'Performance evaluation test company',
                'about' => 'Isolated company used only by PerformanceEvaluationTestSeeder.',
            ]
        );

        $gm = $this->upsertUser(
            'evaluation-gm@'.self::DOMAIN,
            'Evaluation Lab GM',
            Role::GeneralManager->value,
        );

        $this->hrUser = $this->upsertUser(
            'evaluation-hr@'.self::DOMAIN,
            'Evaluation Lab HR',
            Role::HrManager->value,
        );

        $this->department = Department::firstOrCreate(
            [
                'company_id' => $this->company->id,
                'name' => 'Evaluation Engineering',
            ],
            [
                'id' => Str::uuid()->toString(),
                'is_active' => true,
            ]
        );

        $this->managerUser = $this->upsertUser(
            'evaluation-manager@'.self::DOMAIN,
            'Evaluation Lab Manager',
            Role::DepartmentManager->value,
        );

        $this->managerEmployee = $this->upsertEmployee(
            $this->managerUser,
            'Engineering Manager',
            2800,
        );
        $this->department->update(['manager_id' => $this->managerEmployee->id]);

        // Peer reviewer used only as reviewer_id for TYPE_PEER reviews.
        $this->peerUser = $this->upsertUser(
            'evaluation-peer@'.self::DOMAIN,
            'Evaluation Lab Peer',
            Role::Employee->value,
        );
        $this->peerEmployee = $this->upsertEmployee(
            $this->peerUser,
            'Peer Reviewer',
            1400,
        );

        // Ensure GM also has an employee row (matches existing test seeders style).
        $this->upsertEmployee($gm, 'General Manager', 3500);
        $this->upsertEmployee($this->hrUser, 'HR Manager', 2200);

        foreach (self::SCENARIOS as $key => $meta) {
            $user = $this->upsertUser($meta['email'], $meta['name'], Role::Employee->value);
            $employee = $this->upsertEmployee($user, 'Software Engineer', 1500);
            $this->scenarioPeople[$key] = compact('user', 'employee');
        }
    }

    /**
     * Wipe only evaluation artifacts for this lab company so re-seeding is idempotent
     * without truncating unrelated tenant data.
     */
    private function resetEvaluationArtifacts(): void
    {
        $cycleIds = EvaluationCycle::where('company_id', $this->company->id)->pluck('id');

        if ($cycleIds->isNotEmpty()) {
            EvaluationScore::whereIn('evaluation_cycle_id', $cycleIds)->delete();
            $reviewIds = EvaluationReview::whereIn('evaluation_cycle_id', $cycleIds)->pluck('id');
            if ($reviewIds->isNotEmpty()) {
                EvaluationAnswer::whereIn('evaluation_review_id', $reviewIds)->delete();
            }
            EvaluationReview::whereIn('evaluation_cycle_id', $cycleIds)->delete();
            EvaluationCycle::whereIn('id', $cycleIds)->delete();
        }

        $templateIds = EvaluationTemplate::where('company_id', $this->company->id)->pluck('id');
        if ($templateIds->isNotEmpty()) {
            EvaluationTemplateQuestion::whereIn('evaluation_template_id', $templateIds)->delete();
            EvaluationTemplate::whereIn('id', $templateIds)->delete();
        }
    }

    private function createTemplateAndPolicy(): void
    {
        $policy = EvaluationPolicy::firstOrNew(['company_id' => $this->company->id]);
        if (! $policy->exists) {
            $policy->id = Str::uuid()->toString();
        }
        $policy->fill([
            'apply_review_to_salary' => false,
            'peer_reviews_count' => 1,
            'excellent_bonus_percent' => 10,
            'good_bonus_percent' => 5,
            'poor_deduction_percent' => 0,
        ])->save();

        $this->template = EvaluationTemplate::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Evaluation Lab Template',
            'description' => 'Template used by PerformanceEvaluationTestSeeder',
            'is_active' => true,
            'is_archived' => false,
        ]);

        EvaluationTemplateQuestion::create([
            'id' => Str::uuid()->toString(),
            'evaluation_template_id' => $this->template->id,
            'question' => 'Quality of work',
            'response_type' => EvaluationTemplateQuestion::RESPONSE_TYPE_RATING,
            'sort_order' => 0,
            'weight' => 1,
        ]);

        EvaluationTemplateQuestion::create([
            'id' => Str::uuid()->toString(),
            'evaluation_template_id' => $this->template->id,
            'question' => 'Additional comments',
            'response_type' => EvaluationTemplateQuestion::RESPONSE_TYPE_TEXT,
            'sort_order' => 1,
            'weight' => 1,
        ]);

        EvaluationTemplateQuestion::create([
            'id' => Str::uuid()->toString(),
            'evaluation_template_id' => $this->template->id,
            'question' => 'Team collaboration',
            'response_type' => EvaluationTemplateQuestion::RESPONSE_TYPE_RATING,
            'sort_order' => 2,
            'weight' => 1,
        ]);

        $this->template->load('questions');
        $this->ratingQuestion = $this->template->questions->firstWhere('question', 'Quality of work');
        $this->textQuestion = $this->template->questions->firstWhere('question', 'Additional comments');
    }

    private function createCycles(): void
    {
        $this->pastCycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $this->template->id,
            'name' => 'Evaluation Lab — Past Closed Cycle',
            'start_date' => now()->subMonths(4)->toDateString(),
            'end_date' => now()->subMonths(3)->toDateString(),
            'status' => EvaluationCycle::STATUS_CLOSED,
            'updated_at' => now(),
        ]);

        $this->activeCycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $this->template->id,
            'name' => 'Evaluation Lab — Active Cycle',
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addWeeks(3)->toDateString(),
            'status' => EvaluationCycle::STATUS_ACTIVE,
            'updated_at' => now(),
        ]);

        $this->expiredCycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $this->template->id,
            'name' => 'Evaluation Lab — Expired Reviews Cycle',
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->subDays(10)->toDateString(),
            // Keep status active-like historically but dates are past; reviews themselves are expired.
            'status' => EvaluationCycle::STATUS_CLOSED,
            'updated_at' => now(),
        ]);
    }

    private function seedScenarios(): void
    {
        $due = $this->activeCycle->end_date->toDateString();

        // --- Scenario 1: New (not started) ---
        $this->createReviewBundle(
            $this->activeCycle,
            $this->scenarioPeople['new']['employee'],
            $due,
            withPeer: false,
        );

        // --- Scenario 2: Incomplete (draft answers, still pending) ---
        // Note: production submit() is atomic; this seeds a pre-submit draft-like state
        // so Incomplete UI / assistant questions can be tested.
        $incompleteSelf = $this->createReviewBundle(
            $this->activeCycle,
            $this->scenarioPeople['incomplete']['employee'],
            $due,
            withPeer: false,
        )['self'];
        EvaluationAnswer::create([
            'id' => Str::uuid()->toString(),
            'evaluation_review_id' => $incompleteSelf->id,
            'evaluation_template_question_id' => $this->ratingQuestion->id,
            'rating' => 3,
            'comment' => null,
            'hr_score' => null,
        ]);

        // --- Scenario 3: Employee completed self; manager pending ---
        $completed = $this->createReviewBundle(
            $this->activeCycle,
            $this->scenarioPeople['completed']['employee'],
            $due,
            withPeer: false,
        );
        $this->completeReviewWithAnswers($completed['self'], rating: 4, comment: 'Self completed');

        // --- Scenario 4: HR review stage (reviews done, not finalized) ---
        $reviewBundle = $this->createReviewBundle(
            $this->activeCycle,
            $this->scenarioPeople['review']['employee'],
            $due,
            withPeer: false,
        );
        $this->completeReviewWithAnswers($reviewBundle['self'], rating: 4, comment: 'Ready for HR');
        $this->completeReviewWithAnswers($reviewBundle['manager'], rating: 5, comment: 'Manager done');
        // No HR scoring yet — score row may be absent until HR scores.

        // --- Scenario 5: Finalized ---
        $finalBundle = $this->createReviewBundle(
            $this->activeCycle,
            $this->scenarioPeople['finalized']['employee'],
            $due,
            withPeer: false,
        );
        $this->completeReviewWithAnswers($finalBundle['self'], rating: 4, comment: 'Finalized self');
        $this->completeReviewWithAnswers($finalBundle['manager'], rating: 5, comment: 'Finalized manager');
        $this->hrScoreReview($finalBundle['self'], 8);
        $this->hrScoreReview($finalBundle['manager'], 9);
        $this->evaluationService->finalizeEmployeeScore(
            $this->activeCycle,
            $this->scenarioPeople['finalized']['employee']->id,
            $this->hrUser->id,
        );

        // --- Scenario 6: No evaluation — intentionally no reviews ---

        // --- Scenario 7: Multiple (past finalized + current incomplete) ---
        $pastDue = $this->pastCycle->end_date->toDateString();
        $pastBundle = $this->createReviewBundle(
            $this->pastCycle,
            $this->scenarioPeople['multiple']['employee'],
            $pastDue,
            withPeer: false,
        );
        $this->completeReviewWithAnswers($pastBundle['self'], rating: 4, comment: 'Past self');
        $this->completeReviewWithAnswers($pastBundle['manager'], rating: 4, comment: 'Past manager');
        $this->hrScoreReview($pastBundle['self'], 7);
        $this->hrScoreReview($pastBundle['manager'], 8);
        $this->evaluationService->finalizeEmployeeScore(
            $this->pastCycle,
            $this->scenarioPeople['multiple']['employee']->id,
            $this->hrUser->id,
        );

        $currentMultiple = $this->createReviewBundle(
            $this->activeCycle,
            $this->scenarioPeople['multiple']['employee'],
            $due,
            withPeer: false,
        );
        EvaluationAnswer::create([
            'id' => Str::uuid()->toString(),
            'evaluation_review_id' => $currentMultiple['self']->id,
            'evaluation_template_question_id' => $this->ratingQuestion->id,
            'rating' => 2,
            'comment' => null,
            'hr_score' => null,
        ]);

        // --- Scenario 8: Expired pending review ---
        $expiredDue = now()->subDays(5)->toDateString();
        $expiredBundle = $this->createReviewBundle(
            $this->expiredCycle,
            $this->scenarioPeople['expired']['employee'],
            $expiredDue,
            withPeer: false,
        );
        $expiredBundle['self']->update(['status' => EvaluationReview::STATUS_EXPIRED]);
        $expiredBundle['manager']->update(['status' => EvaluationReview::STATUS_EXPIRED]);

        // --- Scenario 9: Mixed review statuses inside one cycle ---
        $mixed = $this->createReviewBundle(
            $this->activeCycle,
            $this->scenarioPeople['mixed']['employee'],
            $due,
            withPeer: true,
        );
        $this->completeReviewWithAnswers($mixed['self'], rating: 4, comment: 'Mixed self done');
        $this->completeReviewWithAnswers($mixed['peer'], rating: 3, comment: 'Mixed peer done');
        // manager remains pending — no answers
    }

    /**
     * @return array{self: EvaluationReview, manager: EvaluationReview, peer: ?EvaluationReview}
     */
    private function createReviewBundle(
        EvaluationCycle $cycle,
        Employee $subject,
        string $dueDate,
        bool $withPeer,
    ): array {
        $self = EvaluationReview::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $subject->id,
            'reviewer_id' => $subject->user_id,
            'review_type' => EvaluationReview::TYPE_SELF,
            'status' => EvaluationReview::STATUS_PENDING,
            'due_date' => $dueDate,
        ]);

        $manager = EvaluationReview::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $subject->id,
            'reviewer_id' => $this->managerUser->id,
            'review_type' => EvaluationReview::TYPE_MANAGER,
            'status' => EvaluationReview::STATUS_PENDING,
            'due_date' => $dueDate,
        ]);

        $peer = null;
        if ($withPeer) {
            $peer = EvaluationReview::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $this->company->id,
                'evaluation_cycle_id' => $cycle->id,
                'employee_id' => $subject->id,
                'reviewer_id' => $this->peerUser->id,
                'review_type' => EvaluationReview::TYPE_PEER,
                'status' => EvaluationReview::STATUS_PENDING,
                'due_date' => $dueDate,
            ]);
        }

        return compact('self', 'manager', 'peer');
    }

    private function completeReviewWithAnswers(EvaluationReview $review, int $rating, string $comment): void
    {
        $answers = [];
        foreach ($this->template->questions as $question) {
            if ($question->response_type === EvaluationTemplateQuestion::RESPONSE_TYPE_RATING) {
                $answers[] = [
                    'question_id' => $question->id,
                    'rating' => $rating,
                ];
            } else {
                $answers[] = [
                    'question_id' => $question->id,
                    'comment' => $comment,
                ];
            }
        }

        // Closed past cycle: submitReview blocks closed cycles — insert completed rows directly.
        if ($review->cycle->isClosed()) {
            $now = now();
            foreach ($answers as $answerData) {
                EvaluationAnswer::create([
                    'id' => Str::uuid()->toString(),
                    'evaluation_review_id' => $review->id,
                    'evaluation_template_question_id' => $answerData['question_id'],
                    'rating' => $answerData['rating'] ?? null,
                    'comment' => $answerData['comment'] ?? null,
                    'hr_score' => null,
                ]);
            }
            $review->update([
                'status' => EvaluationReview::STATUS_COMPLETED,
                'submitted_at' => $now,
            ]);

            return;
        }

        $this->evaluationService->submitReview($review->fresh(['cycle.template.questions']), $answers);
    }

    private function hrScoreReview(EvaluationReview $review, int $hrScore): void
    {
        $review->load('answers.question');

        // For closed cycles, scoreReview is blocked — write hr_score + totals manually.
        if ($review->cycle->isClosed()) {
            foreach ($review->answers as $answer) {
                $answer->update(['hr_score' => $hrScore, 'updated_at' => now()]);
            }
            $this->evaluationService->computeReviewScore($review->fresh());
            $this->evaluationService->updateEmployeeScores($review->cycle, $review->employee_id);

            return;
        }

        $scores = $review->answers
            ->filter(fn (EvaluationAnswer $a) => $a->question !== null)
            ->map(fn (EvaluationAnswer $a) => [
                'answer_id' => $a->id,
                'hr_score' => $hrScore,
            ])
            ->values()
            ->all();

        $this->evaluationService->scoreReview($review->fresh(['cycle']), $scores);
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

    private function upsertEmployee(User $user, string $jobTitle, float $salary): Employee
    {
        $employee = Employee::where('user_id', $user->id)->first();

        if ($employee) {
            $employee->update([
                'company_id' => $this->company->id,
                'department_id' => $this->department->id,
                'job_title' => $jobTitle,
                'base_salary' => $salary,
                'employment_type' => 'full-time',
                'is_active' => true,
            ]);

            return $employee->fresh();
        }

        return Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => $jobTitle,
            'base_salary' => $salary,
            'hire_date' => now()->subYears(1)->toDateString(),
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
    }

    private function printCredentials(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info('==================================================');
        $this->command->info('PerformanceEvaluationTestSeeder ready');
        $this->command->info('Company: '.$this->company->name.' ('.$this->company->id.')');
        $this->command->info('Active cycle: '.$this->activeCycle->name.' ('.$this->activeCycle->id.')');
        $this->command->info('Past cycle:   '.$this->pastCycle->name.' ('.$this->pastCycle->id.')');
        $this->command->info('Expired cycle:'.$this->expiredCycle->name.' ('.$this->expiredCycle->id.')');
        $this->command->info('--------------------------------------------------');
        $this->command->info('Staff logins (password: '.self::PASSWORD.')');
        $this->command->info('  HR:      evaluation-hr@'.self::DOMAIN);
        $this->command->info('  Manager: evaluation-manager@'.self::DOMAIN);
        $this->command->info('  Peer:    evaluation-peer@'.self::DOMAIN);
        $this->command->info('  GM:      evaluation-gm@'.self::DOMAIN);
        $this->command->info('--------------------------------------------------');
        $this->command->info('Scenario employees (password: '.self::PASSWORD.')');

        foreach (self::SCENARIOS as $meta) {
            $this->command->info('  '.$meta['email'].'  — '.$meta['scenario']);
        }

        $this->command->info('--------------------------------------------------');
        $this->command->info('Useful APIs:');
        $this->command->info('  GET  /api/evaluations/my-reviews');
        $this->command->info('  GET  /api/evaluations/my-reviews/{review}');
        $this->command->info('  POST /api/employee/assistant/chat  {"message":"هل عندي تقييم لازم عبّيه؟"}');
        $this->command->info('  GET  /api/hr/evaluation-cycles/{cycle}/final-results/{employee}');
        $this->command->info('==================================================');
    }
}
