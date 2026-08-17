<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateHrManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('hr_manager')->id),
            ],
            'phone' => ['sometimes', 'string', 'regex:/^09[0-9]{8}$/'],
            'department_id' => ['sometimes', 'string', 'exists:departments,id'],
            'job_title' => ['sometimes', 'string', 'max:255'],
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'hire_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'employment_type' => ['sometimes', 'string', 'max:100'],
            'education' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'in:single,married,divorced,widowed'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'residence' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone number must start with 09 and contain 10 digits.',
            'hire_date.before_or_equal' => 'Hire date cannot be in the future.',
            'birth_date.before_or_equal' => 'Birth date cannot be in the future.',
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
