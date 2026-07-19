<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = (int) ($this->total_reviews ?? 0);
        $completed = (int) ($this->completed_reviews ?? 0);
        $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        return [
            'employee_id' => $this->employee_id,
            'employee' => [
                'id' => $this->employee?->id,
                'employee_code' => $this->employee?->employee_code,
                'full_name' => $this->employee?->user?->full_name,
                'email' => $this->employee?->user?->email,
                'job_title' => $this->employee?->job_title,
                'department' => $this->employee?->department ? [
                    'id' => $this->employee->department->id,
                    'name' => $this->employee->department->name,
                ] : null,
            ],
            'department_name' => $this->employee?->department?->name,
            'assigned_reviews' => $total,
            'completed_reviews' => $completed,
            'completion_percentage' => $percentage,
            'status' => $completed >= $total && $total > 0 ? 'Completed' : 'Pending',
        ];
    }
}
