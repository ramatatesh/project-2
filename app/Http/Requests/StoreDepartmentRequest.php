<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')
                ->where(fn ($q) => $q->where('company_id', $companyId))],
            'is_active' => ['sometimes', 'boolean'],
            'manager_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('employees', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A department with this name already exists in your company.',
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
