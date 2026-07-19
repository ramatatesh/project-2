<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationCycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'evaluation_template_id' => $this->evaluation_template_id,
            'name' => $this->name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status,
            'is_closed' => $this->isClosed(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'template' => new EvaluationTemplateResource($this->whenLoaded('template')),
            'reviews_count' => $this->when(isset($this->reviews_count), $this->reviews_count),
            'completed_reviews_count' => $this->when(isset($this->completed_reviews_count), $this->completed_reviews_count),
        ];
    }
}
