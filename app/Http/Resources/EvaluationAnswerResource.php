<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_review_id' => $this->evaluation_review_id,
            'evaluation_template_question_id' => $this->evaluation_template_question_id,
            'question' => $this->whenLoaded('question', fn () => [
                'id' => $this->question->id,
                'question' => $this->question->question,
                'response_type' => $this->question->response_type,
                'sort_order' => $this->question->sort_order,
            ]),
            'rating' => $this->rating,
            'comment' => $this->comment,
            'hr_score' => $this->hr_score,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
