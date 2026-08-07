<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $departmentId = $this->route('department')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('departments', 'name')
                ->where(fn ($q) => $q->where('company_id', $companyId))
                ->ignore($departmentId, 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'manager_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('employees', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->where('department_id', $departmentId)),
                function ($attribute, $value, $fail) use ($departmentId) {
                    if (! $value) {
                        return;
                    }

                    $managesAnotherDepartment = Department::where('manager_id', $value)
                        ->where('id', '!=', $departmentId)
                        ->exists();

                    if ($managesAnotherDepartment) {
                        $fail('This employee is already the manager of another department. Remove them from that department first.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A department with this name already exists in your company.',
            'manager_id.exists' => 'The selected employee must belong to this department.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
