<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class HrManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['sometimes', 'string', 'max:50'],
            'department_id' => ['required', 'string', 'exists:departments,id'],
            'job_title' => ['required', 'string', 'max:255'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'hire_date' => ['required', 'date'],
            'employment_type' => ['sometimes', 'string', 'max:100'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('employees', 'employee_code')],
            'education' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
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
