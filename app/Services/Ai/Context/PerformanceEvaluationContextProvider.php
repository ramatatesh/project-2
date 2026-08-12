<?php

namespace App\Services\Ai\Context;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\Employee;
use App\Models\EvaluationCycle;
use App\Models\EvaluationReview;
use App\Models\EvaluationScore;
use App\Models\User;
use App\Services\Ai\Concerns\MatchesAssistantTopics;

/**
 * Read-only, employee-scoped performance evaluation context for the AI assistant.
 * Does not change evaluation workflows; only exposes data the employee may already see.
 */
class PerformanceEvaluationContextProvider implements EmployeeContextProvider
{
    use MatchesAssistantTopics;

    public function key(): string
    {
        return 'performance';
    }

    public function supports(string $message): bool
    {
        return $this->matchesAny($message, [
            'تقييم', 'تقييمي', 'تقييمات', 'performance', 'evaluation', 'review',
            'درجة', 'نتيجتي', 'نتيجة التقييم', 'دورة تقييم', 'عبّيه', 'عبيه',
            'self review', 'peer review',
        ]);
    }

    public function build(Employee $employee, User $user): array
    {
        $companyId = $employee->company_id;

        $assignedReviews = EvaluationReview::query()
            ->where('company_id', $companyId)
            ->where('reviewer_id', $user->id)
            ->with(['cycle:id,name,status,start_date,end_date', 'employee.user:id,full_name'])
            ->orderByDesc('created_at')
            ->get();

        $pendingAssigned = $assignedReviews
            ->where('status', EvaluationReview::STATUS_PENDING)
            ->values();

        $subjectReviews = EvaluationReview::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['cycle:id,name,status,start_date,end_date'])
            ->orderByDesc('created_at')
            ->get();

        $scores = EvaluationScore::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['cycle:id,name,status,start_date,end_date'])
            ->orderByDesc('created_at')
            ->get();

        $cycleIds = $subjectReviews->pluck('evaluation_cycle_id')
            ->merge($assignedReviews->pluck('evaluation_cycle_id'))
            ->merge($scores->pluck('evaluation_cycle_id'))
            ->unique()
            ->filter()
            ->values();

        $cycles = EvaluationCycle::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $cycleIds)
            ->orderByDesc('start_date')
            ->get();

        $activeCycles = $cycles->filter(function (EvaluationCycle $cycle) {
            return $cycle->status === EvaluationCycle::STATUS_ACTIVE && ! $cycle->isClosed();
        })->values();

        return [
            'has_any_evaluation_data' => $assignedReviews->isNotEmpty() || $subjectReviews->isNotEmpty() || $scores->isNotEmpty(),
            'requires_employee_action' => $pendingAssigned->isNotEmpty(),
            'pending_reviews_count' => $pendingAssigned->count(),
            'pending_reviews' => $pendingAssigned->map(function (EvaluationReview $review) {
                return [
                    'review_id' => $review->id,
                    'review_type' => $review->review_type,
                    'status' => $review->status,
                    'due_date' => optional($review->due_date)?->toDateString(),
                    'cycle_name' => $review->cycle?->name,
                    'cycle_status' => $review->cycle?->status,
                    'about_employee_name' => $review->employee?->user?->full_name,
                    // Self reviews: about_employee is the authenticated employee themselves.
                    'is_self_review' => $review->review_type === EvaluationReview::TYPE_SELF,
                ];
            })->all(),
            'my_evaluations_as_subject' => $this->groupSubjectReviewsByCycle($subjectReviews, $scores),
            'recent_finalized_scores' => $scores
                ->where('status', EvaluationScore::STATUS_FINALIZED)
                ->take(5)
                ->values()
                ->map(function (EvaluationScore $score) {
                    return [
                        'cycle_name' => $score->cycle?->name,
                        'cycle_status' => $score->cycle?->status,
                        'final_score' => $score->final_score !== null ? (float) $score->final_score : null,
                        'manager_score' => $score->manager_score !== null ? (float) $score->manager_score : null,
                        'self_score' => $score->self_score !== null ? (float) $score->self_score : null,
                        'peer_score' => $score->peer_score !== null ? (float) $score->peer_score : null,
                        'finalized_at' => optional($score->finalized_at)?->toIso8601String(),
                    ];
                })->all(),
            'active_cycles_summary' => $activeCycles->map(function (EvaluationCycle $cycle) {
                return [
                    'name' => $cycle->name,
                    'status' => $cycle->status,
                    'start_date' => optional($cycle->start_date)?->toDateString(),
                    'end_date' => optional($cycle->end_date)?->toDateString(),
                ];
            })->all(),
        ];
    }

    private function groupSubjectReviewsByCycle($subjectReviews, $scores): array
    {
        return $subjectReviews
            ->groupBy('evaluation_cycle_id')
            ->map(function ($reviews) use ($scores) {
                /** @var EvaluationReview $first */
                $first = $reviews->first();
                $cycle = $first->cycle;
                $score = $scores->firstWhere('evaluation_cycle_id', $first->evaluation_cycle_id);

                $reviewStatuses = $reviews->map(fn (EvaluationReview $r) => [
                    'review_type' => $r->review_type,
                    'status' => $r->status,
                    'due_date' => optional($r->due_date)?->toDateString(),
                    'submitted_at' => optional($r->submitted_at)?->toIso8601String(),
                    // total_score is HR-scored; only expose when the employee score is finalized.
                    'total_score' => ($score && $score->status === EvaluationScore::STATUS_FINALIZED)
                        ? ($r->total_score !== null ? (float) $r->total_score : null)
                        : null,
                ])->values()->all();

                $allCompleted = $reviews->every(fn (EvaluationReview $r) => $r->status === EvaluationReview::STATUS_COMPLETED);

                return [
                    'cycle_name' => $cycle?->name,
                    'cycle_status' => $cycle?->status,
                    'cycle_start_date' => optional($cycle?->start_date)?->toDateString(),
                    'cycle_end_date' => optional($cycle?->end_date)?->toDateString(),
                    'all_reviews_completed' => $allCompleted,
                    'reviews' => $reviewStatuses,
                    'score' => $score ? [
                        'status' => $score->status,
                        'final_score' => $score->status === EvaluationScore::STATUS_FINALIZED
                            ? ($score->final_score !== null ? (float) $score->final_score : null)
                            : null,
                        'manager_score' => $score->status === EvaluationScore::STATUS_FINALIZED
                            ? ($score->manager_score !== null ? (float) $score->manager_score : null)
                            : null,
                        'self_score' => $score->status === EvaluationScore::STATUS_FINALIZED
                            ? ($score->self_score !== null ? (float) $score->self_score : null)
                            : null,
                        'peer_score' => $score->status === EvaluationScore::STATUS_FINALIZED
                            ? ($score->peer_score !== null ? (float) $score->peer_score : null)
                            : null,
                        'finalized_at' => $score->status === EvaluationScore::STATUS_FINALIZED
                            ? optional($score->finalized_at)?->toIso8601String()
                            : null,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }
}
