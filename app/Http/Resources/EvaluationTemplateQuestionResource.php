<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationTemplateQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_template_id' => $this->evaluation_template_id,
            'question' => $this->question,
            'response_type' => $this->response_type,
            'sort_order' => $this->sort_order,
            'weight' => $this->weight,
            'created_at' => $this->created_at,
            'answer' => $this->when($this->pivot && isset($this->pivot->rating), [
                'rating' => $this->pivot?->rating,
                'comment' => $this->pivot?->comment,
                'hr_score' => $this->pivot?->hr_score,
            ]),
        ];
    }
}
