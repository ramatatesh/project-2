<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EvaluationAnswer;
use App\Models\EvaluationCycle;
use App\Models\EvaluationPolicy;
use App\Models\EvaluationReview;
use App\Models\EvaluationScore;
use App\Models\EvaluationTemplate;
use App\Models\EvaluationTemplateQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;

class EvaluationService
{
    public function createTemplate(array $data, string $companyId): EvaluationTemplate
    {
        $template = EvaluationTemplate::create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_archived' => $data['is_archived'] ?? false,
        ]);

        if (! empty($data['questions'])) {
            $this->createQuestions($template->id, $data['questions']);
        }

        return $template->load('questions');
    }

    public function updateTemplate(EvaluationTemplate $template, array $data): EvaluationTemplate
    {
        $template->update([
            'name' => $data['name'] ?? $template->name,
            'description' => $data['description'] ?? $template->description,
            'is_active' => $data['is_active'] ?? $template->is_active,
            'is_archived' => $data['is_archived'] ?? $template->is_archived,
        ]);

        if (isset($data['questions'])) {
            $this->syncQuestions($template, $data['questions']);
        }

        return $template->load('questions');
    }

    public function deleteTemplate(EvaluationTemplate $template): void
    {
        $template->delete();
    }

    public function duplicateTemplate(EvaluationTemplate $template, string $newName, bool $archiveSource = false): EvaluationTemplate
    {
        $newTemplate = EvaluationTemplate::create([
            'company_id' => $template->company_id,
            'name' => $newName,
            'description' => $template->description,
            'is_active' => true,
            'is_archived' => false,
        ]);

        foreach ($template->questions as $question) {
            EvaluationTemplateQuestion::create([
                'evaluation_template_id' => $newTemplate->id,
                'question' => $question->question,
                'response_type' => $question->response_type,
                'sort_order' => $question->sort_order,
                'weight' => $question->weight,
            ]);
        }

        if ($archiveSource) {
            $template->update(['is_archived' => true]);
        }

        return $newTemplate->load('questions');
    }

    public function archiveTemplate(EvaluationTemplate $template, bool $archived = true): void
    {
        $template->update(['is_archived' => $archived]);
    }

    public function createCycle(array $data, string $companyId): EvaluationCycle
    {
        $this->ensureTemplateBelongsToCompany($data['evaluation_template_id'], $companyId);

        return EvaluationCycle::create([
            'company_id' => $companyId,
            'evaluation_template_id' => $data['evaluation_template_id'],
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => $data['status'] ?? EvaluationCycle::STATUS_DRAFT,
            'updated_at' => now(),
        ]);
    }

    public function updateCycle(EvaluationCycle $cycle, array $data): EvaluationCycle
    {
        if (isset($data['evaluation_template_id'])) {
            $this->ensureTemplateBelongsToCompany($data['evaluation_template_id'], $cycle->company_id);
        }

        $cycle->update([
            'name' => $data['name'] ?? $cycle->name,
            'evaluation_template_id' => $data['evaluation_template_id'] ?? $cycle->evaluation_template_id,
            'start_date' => $data['start_date'] ?? $cycle->start_date,
            'end_date' => $data['end_date'] ?? $cycle->end_date,
            'status' => $data['status'] ?? $cycle->status,
            'updated_at' => now(),
        ]);

        return $cycle;
    }

    public function deleteCycle(EvaluationCycle $cycle): void
    {
        $cycle->delete();
    }

    public function launchCycle(EvaluationCycle $cycle, ?string $dueDate = null): array
    {
        if ($cycle->status === EvaluationCycle::STATUS_CLOSED || $cycle->isClosed()) {
            throw new RuntimeException('Cannot launch a closed evaluation cycle.');
        }

        $policy = EvaluationPolicy::firstOrCreate(
            ['company_id' => $cycle->company_id],
            ['company_id' => $cycle->company_id, 'apply_review_to_salary' => false]
        );

        if ($policy->peer_reviews_count === null || $policy->peer_reviews_count < 0) {
            throw new RuntimeException('Peer reviews count is not configured in the evaluation policy.');
        }

        if ($cycle->reviews()->exists()) {
            throw new RuntimeException('This cycle has already been launched.');
        }

        $cycle->load('template.questions');
        $template = $cycle->template;

        if (! $template || $template->questions->isEmpty()) {
            throw new RuntimeException('Evaluation template must have at least one question before launching.');
        }

        $due = $dueDate ?? $cycle->end_date;
        $employees = Employee::where('company_id', $cycle->company_id)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->with('department.manager.user')
            ->get();

        $companyGeneralManager = User::where('company_id', $cycle->company_id)
            ->where('role', 'general_manager')
            ->first();

        $created = 0;

        foreach ($employees as $employee) {
            $created += $this->createSelfReview($cycle, $employee, $due);
            $managerUserId = $this->resolveManagerReviewerId($employee, $companyGeneralManager);
            $created += $this->createManagerReview($cycle, $employee, $managerUserId, $due);

            $peerUserIds = $this->selectPeerReviewers($employee, $employees, $managerUserId, $policy->peer_reviews_count);
            foreach ($peerUserIds as $peerUserId) {
                $created += $this->createPeerReview($cycle, $employee, $peerUserId, $due);
            }
        }

        $cycle->update(['status' => EvaluationCycle::STATUS_ACTIVE, 'updated_at' => now()]);

        return ['created_reviews' => $created];
    }

    public function closeCycle(EvaluationCycle $cycle): EvaluationCycle
    {
        $cycle->update(['status' => EvaluationCycle::STATUS_CLOSED, 'updated_at' => now()]);

        return $cycle;
    }

    public function submitReview(EvaluationReview $review, array $answersData): void
    {
        if ($review->cycle->isClosed()) {
            throw new RuntimeException('Evaluation cycle is closed. Submissions are not allowed.');
        }

        $allowedQuestionIds = $review->cycle->template->questions->pluck('id')->toArray();
        $now = now();

        foreach ($answersData as $answerData) {
            if (! in_array($answerData['question_id'], $allowedQuestionIds, true)) {
                throw new InvalidArgumentException('Invalid question for this cycle template.');
            }

            EvaluationAnswer::updateOrCreate(
                [
                    'evaluation_review_id' => $review->id,
                    'evaluation_template_question_id' => $answerData['question_id'],
                ],
                [
                    'rating' => $answerData['rating'] ?? null,
                    'comment' => $answerData['comment'] ?? null,
                    'hr_score' => $answerData['hr_score'] ?? null,
                    'updated_at' => $now,
                ]
            );
        }

        $review->update([
            'status' => EvaluationReview::STATUS_COMPLETED,
            'submitted_at' => $now,
        ]);
    }

    public function scoreReview(EvaluationReview $review, array $scoresData): void
    {
        $now = now();

        foreach ($scoresData as $scoreData) {
            $answer = EvaluationAnswer::where('id', $scoreData['answer_id'])
                ->where('evaluation_review_id', $review->id)
                ->first();

            if (! $answer) {
                throw new InvalidArgumentException('Answer does not belong to this review.');
            }

            $answer->update([
                'hr_score' => $scoreData['hr_score'],
                'updated_at' => $now,
            ]);
        }

        $this->computeReviewScore($review);
        $this->updateEmployeeScores($review->cycle, $review->employee_id);
    }

    public function computeReviewScore(EvaluationReview $review): void
    {
        $review->load('answers.question');

        $scores = $review->answers
            ->filter(fn (EvaluationAnswer $answer) => $answer->question->response_type === EvaluationTemplateQuestion::RESPONSE_TYPE_RATING && $answer->hr_score !== null)
            ->pluck('hr_score');

        $total = $scores->sum();
        $count = $scores->count();

        $review->update(['total_score' => $count > 0 ? round($total / $count, 2) : null]);
    }

    public function updateEmployeeScores(EvaluationCycle $cycle, string $employeeId): EvaluationScore
    {
        $policy = EvaluationPolicy::firstOrCreate(
            ['company_id' => $cycle->company_id],
            ['company_id' => $cycle->company_id, 'apply_review_to_salary' => false]
        );

        $managerScore = $this->averageReviewScores($cycle->id, $employeeId, EvaluationReview::TYPE_MANAGER);
        $selfScore = $this->averageReviewScores($cycle->id, $employeeId, EvaluationReview::TYPE_SELF);
        $peerScore = $this->averageReviewScores($cycle->id, $employeeId, EvaluationReview::TYPE_PEER);

        $weights = [
            'manager' => (float) ($policy->manager_weight ?? 0),
            'self' => (float) ($policy->self_weight ?? 0),
            'peer' => (float) ($policy->peer_weight ?? 0),
        ];

        $weightedSum = 0;
        $weightTotal = 0;

        foreach (['manager' => $managerScore, 'self' => $selfScore, 'peer' => $peerScore] as $type => $score) {
            if ($score !== null && $weights[$type] > 0) {
                $weightedSum += $score * $weights[$type];
                $weightTotal += $weights[$type];
            }
        }

        $finalScore = $weightTotal > 0 ? round($weightedSum / $weightTotal, 2) : null;

        return EvaluationScore::updateOrCreate(
            [
                'evaluation_cycle_id' => $cycle->id,
                'employee_id' => $employeeId,
            ],
            [
                'company_id' => $cycle->company_id,
                'manager_score' => $managerScore,
                'self_score' => $selfScore,
                'peer_score' => $peerScore,
                'final_score' => $finalScore,
                'status' => EvaluationScore::STATUS_PENDING,
            ]
        );
    }

    public function getProgressForCycle(EvaluationCycle $cycle): Collection
    {
        $employeeIds = Employee::where('company_id', $cycle->company_id)
            ->where('is_active', true)
            ->pluck('id');

        return Employee::whereIn('id', $employeeIds)
            ->with(['user', 'department'])
            ->get()
            ->map(function (Employee $employee) use ($cycle) {
                $employee->total_reviews = $cycle->reviews()->where('employee_id', $employee->id)->count();
                $employee->completed_reviews = $cycle->reviews()
                    ->where('employee_id', $employee->id)
                    ->where('status', EvaluationReview::STATUS_COMPLETED)
                    ->count();

                return $employee;
            });
    }

    public function getEmployeesReadyForScoring(EvaluationCycle $cycle): Collection
    {
        $employeeIds = $cycle->reviews()
            ->distinct()
            ->pluck('employee_id')
            ->toArray();

        $readyIds = [];

        foreach ($employeeIds as $employeeId) {
            $total = $cycle->reviews()->where('employee_id', $employeeId)->count();
            $completed = $cycle->reviews()
                ->where('employee_id', $employeeId)
                ->where('status', EvaluationReview::STATUS_COMPLETED)
                ->count();

            if ($total > 0 && $completed === $total) {
                $readyIds[] = $employeeId;
            }
        }

        return Employee::whereIn('id', $readyIds)
            ->with(['user', 'department'])
            ->get();
    }

    public function getReviewDetails(EvaluationReview $review): EvaluationReview
    {
        return $review->load(['answers.question', 'employee.user', 'employee.department', 'reviewer']);
    }

    public function getScoringDetails(EvaluationCycle $cycle, string $employeeId, ?string $reviewType = null): Collection
    {
        $query = $cycle->reviews()
            ->where('employee_id', $employeeId)
            ->where('status', EvaluationReview::STATUS_COMPLETED)
            ->with(['answers.question', 'reviewer', 'employee.user']);

        if ($reviewType) {
            $query->where('review_type', $reviewType);
        }

        return $query->get();
    }

    public function finalizeEmployeeScore(EvaluationCycle $cycle, string $employeeId, string $finalizedByUserId): EvaluationScore
    {
        $score = $this->updateEmployeeScores($cycle, $employeeId);
        $score->update([
            'status' => EvaluationScore::STATUS_FINALIZED,
            'finalized_by' => $finalizedByUserId,
            'finalized_at' => now(),
        ]);

        return $score;
    }

    public function sendReminder(EvaluationCycle $cycle, Employee $employee): void
    {
        $pendingCount = $cycle->reviews()
            ->where('employee_id', $employee->id)
            ->where('status', EvaluationReview::STATUS_PENDING)
            ->count();

        if ($pendingCount === 0) {
            return;
        }

        // Placeholder: this hook should be connected to the notification system later.
        // Example: Notification::send($employee->user, new EvaluationReminder($cycle, $employee));
    }

    public function ensureOwnsCompany($resource, string $companyId): void
    {
        if ($resource->company_id !== $companyId) {
            abort(403, 'You do not have access to this resource.');
        }
    }

    private function createSelfReview(EvaluationCycle $cycle, Employee $employee, string $due): int
    {
        if (! $employee->user_id) {
            return 0;
        }

        EvaluationReview::firstOrCreate(
            [
                'evaluation_cycle_id' => $cycle->id,
                'employee_id' => $employee->id,
                'reviewer_id' => $employee->user_id,
                'review_type' => EvaluationReview::TYPE_SELF,
            ],
            [
                'company_id' => $cycle->company_id,
                'status' => EvaluationReview::STATUS_PENDING,
                'due_date' => $due,
            ]
        );

        return 1;
    }

    private function createManagerReview(EvaluationCycle $cycle, Employee $employee, ?string $managerUserId, string $due): int
    {
        if (! $managerUserId) {
            return 0;
        }

        EvaluationReview::firstOrCreate(
            [
                'evaluation_cycle_id' => $cycle->id,
                'employee_id' => $employee->id,
                'reviewer_id' => $managerUserId,
                'review_type' => EvaluationReview::TYPE_MANAGER,
            ],
            [
                'company_id' => $cycle->company_id,
                'status' => EvaluationReview::STATUS_PENDING,
                'due_date' => $due,
            ]
        );

        return 1;
    }

    private function createPeerReview(EvaluationCycle $cycle, Employee $employee, string $peerUserId, string $due): int
    {
        EvaluationReview::firstOrCreate(
            [
                'evaluation_cycle_id' => $cycle->id,
                'employee_id' => $employee->id,
                'reviewer_id' => $peerUserId,
                'review_type' => EvaluationReview::TYPE_PEER,
            ],
            [
                'company_id' => $cycle->company_id,
                'status' => EvaluationReview::STATUS_PENDING,
                'due_date' => $due,
            ]
        );

        return 1;
    }

    private function resolveManagerReviewerId(Employee $employee, ?User $companyGeneralManager): ?string
    {
        $managerUserId = $employee->department?->manager?->user_id;

        if (! $managerUserId && $companyGeneralManager) {
            $managerUserId = $companyGeneralManager->id;
        }

        if ($managerUserId === $employee->user_id) {
            $managerUserId = $companyGeneralManager?->id === $employee->user_id
                ? null
                : $companyGeneralManager?->id;
        }

        return $managerUserId;
    }

    private function selectPeerReviewers(Employee $employee, Collection $employees, ?string $managerUserId, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $excludedUserIds = array_filter([$employee->user_id, $managerUserId]);

        $candidates = $employees
            ->where('id', '!==', $employee->id)
            ->whereNotNull('user_id')
            ->reject(fn (Employee $candidate) => in_array($candidate->user_id, $excludedUserIds, true))
            ->shuffle()
            ->take($count)
            ->pluck('user_id')
            ->toArray();

        return array_unique($candidates);
    }

    private function createQuestions(string $templateId, array $questions): void
    {
        foreach ($questions as $index => $question) {
            EvaluationTemplateQuestion::create([
                'evaluation_template_id' => $templateId,
                'question' => $question['question'],
                'response_type' => $question['response_type'],
                'sort_order' => $question['sort_order'] ?? $index,
                'weight' => $question['weight'] ?? 1,
            ]);
        }
    }

    private function syncQuestions(EvaluationTemplate $template, array $questions): void
    {
        $incomingIds = collect($questions)->pluck('id')->filter()->toArray();
        $template->questions()->whereNotIn('id', $incomingIds)->delete();

        foreach ($questions as $index => $question) {
            EvaluationTemplateQuestion::updateOrCreate(
                [
                    'id' => $question['id'] ?? null,
                    'evaluation_template_id' => $template->id,
                ],
                [
                    'question' => $question['question'],
                    'response_type' => $question['response_type'],
                    'sort_order' => $question['sort_order'] ?? $index,
                    'weight' => $question['weight'] ?? 1,
                ]
            );
        }
    }

    private function ensureTemplateBelongsToCompany(string $templateId, string $companyId): void
    {
        $template = EvaluationTemplate::findOrFail($templateId);
        if ($template->company_id !== $companyId) {
            abort(403, 'Template does not belong to your company.');
        }
    }

    private function averageReviewScores(string $cycleId, string $employeeId, string $type): ?float
    {
        $average = EvaluationReview::where('evaluation_cycle_id', $cycleId)
            ->where('employee_id', $employeeId)
            ->where('review_type', $type)
            ->where('status', EvaluationReview::STATUS_COMPLETED)
            ->whereNotNull('total_score')
            ->avg('total_score');

        return $average !== null ? round((float) $average, 2) : null;
    }
}
