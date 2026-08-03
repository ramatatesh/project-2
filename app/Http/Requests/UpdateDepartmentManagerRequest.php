<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateDepartmentManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $manager = $this->route('department_manager');

        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($manager?->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'department_id' => [
                'sometimes',
                'uuid',
                function ($attribute, $value, $fail) use ($companyId) {
                    $exists = Department::where('id', $value)
                        ->where('company_id', $companyId)
                        ->exists();

                    if (! $exists) {
                        $fail('The selected department is invalid or does not belong to your company.');
                    }
                },
            ],
            'job_title' => ['sometimes', 'string', 'max:255'],
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'hire_date' => ['sometimes', 'date'],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'education' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'in:single,married,divorced,widowed'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'residence' => ['sometimes', 'nullable', 'string', 'max:255'],
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
