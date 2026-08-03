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
        $expired = (int) ($this->expired_reviews ?? 0);
        $pending = (int) ($this->pending_reviews ?? max(0, $total - $completed - $expired));
        $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

       return [
           'employee_id' => $this->id,

            'employee' => [
               'id' => $this->id,
               'full_name' => $this->user?->full_name,
               'email' => $this->user?->email,
               'job_title' => $this->job_title,

                'department' => $this->department ? [
                 'id' => $this->department->id,
                 'name' => $this->department->name,
                 ] : null,
            ],

           'department_name' => $this->department?->name,

           'assigned_reviews' => $total,
           'completed_reviews' => $completed,
           'expired_reviews' => $expired,
           'pending_reviews' => $pending,
           'completion_percentage' => $percentage,

           'status' => $completed >= $total && $total > 0
            ? 'Completed'
            : 'Pending',
        ];
    }
}
