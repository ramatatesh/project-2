<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_cycle_id' => $this->evaluation_cycle_id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_code' => $this->employee->employee_code,
                'full_name' => $this->employee->user?->full_name,
                'job_title' => $this->employee->job_title,
            ]),
            'manager_score' => $this->manager_score,
            'self_score' => $this->self_score,
            'peer_score' => $this->peer_score,
            'final_score' => $this->final_score,
            'status' => $this->status,
            'finalized_at' => $this->finalized_at,
            'created_at' => $this->created_at,
        ];
    }
}
