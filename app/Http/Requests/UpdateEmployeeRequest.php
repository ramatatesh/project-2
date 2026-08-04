<?php

namespace App\Http\Requests;

use App\Services\EmployeeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $employee = $this->route('employee');
        $userId = $employee?->user_id;

        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId, 'id')],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^09[0-9]{8}$/'],
            'department_id' => ['sometimes', 'uuid', 'exists:departments,id', function ($attribute, $value, $fail) use ($companyId) {
                if (! EmployeeService::departmentBelongsToCompany($value, (string) $companyId)) {
                    $fail('The selected department does not belong to your company.');
                }
            }],
            'education' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title' => ['sometimes', 'string', 'max:255'],
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'hire_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'in:single,married,divorced,widowed'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'residence' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'رقم الهاتف يجب أن يبدأ بـ 09 ويتكون من 10 أرقام.',
            'hire_date.before_or_equal' => 'لا يمكن أن يكون تاريخ التعيين في المستقبل.',
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
