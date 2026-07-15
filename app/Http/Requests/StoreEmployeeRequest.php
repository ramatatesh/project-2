<?php

namespace App\Http\Requests;

use App\Services\EmployeeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'department_id' => ['required', 'uuid', 'exists:departments,id', function ($attribute, $value, $fail) use ($companyId) {
                if (! EmployeeService::departmentBelongsToCompany($value, (string) $companyId)) {
                    $fail('The selected department does not belong to your company.');
                }
            }],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('employees', 'employee_code')],
            'education' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'hire_date' => ['required', 'date'],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:100'],
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
