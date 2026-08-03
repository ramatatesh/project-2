<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'evaluation_cycle_id' => $this->evaluation_cycle_id,
            'employee_id' => $this->employee_id,
            'reviewer_id' => $this->reviewer_id,
            'review_type' => $this->review_type,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'due_date' => $this->due_date,
            'total_score' => $this->total_score,
            'created_at' => $this->created_at,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'job_title' => $this->employee->job_title,
                'user' => $this->employee->user ? [
                    'id' => $this->employee->user->id,
                    'full_name' => $this->employee->user->full_name,
                    'email' => $this->employee->user->email,
                ] : null,
                'department' => $this->employee->department ? [
                    'id' => $this->employee->department->id,
                    'name' => $this->employee->department->name,
                ] : null,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer->id,
                'full_name' => $this->reviewer->full_name,
                'email' => $this->reviewer->email,
            ]),
            'answers' => EvaluationAnswerResource::collection($this->whenLoaded('answers')),
            'questions_count' => $this->cycle->template->questions->count(),
            'questions' => $this->whenLoaded('cycle', function () {
                return EvaluationQuestionResource::collection(
                    $this->cycle->template->questions
               );
            }),
            'cycle' => [
                'id' => $this->cycle->id,
                'name' => $this->cycle->name,
                'start_date' => $this->cycle->start_date,
                'end_date' => $this->cycle->end_date,
            ],
        ];
    }
}
