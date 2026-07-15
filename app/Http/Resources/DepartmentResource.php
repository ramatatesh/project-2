<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => [
                'id' => $this->manager->id,
                'job_title' => $this->manager->job_title,
            ]),
            'employees_count' => $this->when(isset($this->employees_count), $this->employees_count),
            'created_at' => $this->created_at,
        ];
    }
}
