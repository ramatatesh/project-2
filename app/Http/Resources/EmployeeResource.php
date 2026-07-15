<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'department_id' => $this->department_id,
            'employee_code' => $this->employee_code,
            'education' => $this->education,
            'job_title' => $this->job_title,
            'base_salary' => $this->base_salary,
            'hire_date' => $this->hire_date,
            'employment_type' => $this->employment_type,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'role' => $this->user->role,
                'status' => $this->user->status,
            ]),
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
        ];
    }
}
