<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'department_id' => $this->department_id,
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
                'gender' => $this->user->gender,
                'marital_status' => $this->user->marital_status,
                'nationality' => $this->user->nationality,
                'residence' => $this->user->residence,
                'profile_completed' => $this->user->profile_completed,
            ]),
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'document' => $this->whenLoaded('document', fn () => $this->document ? [
                'profile_image_url' => $this->document->profile_image_path ? Storage::disk('public')->url($this->document->profile_image_path) : null,
                'identity_image_url' => $this->document->identity_image_path ? Storage::disk('public')->url($this->document->identity_image_path) : null,
                'university_certificate_url' => $this->document->university_certificate_path ? Storage::disk('public')->url($this->document->university_certificate_path) : null,
            ] : null),
        ];
    }
}
