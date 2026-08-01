<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSalaryAdvancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'max_advance_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_repayment_months' => ['required', 'integer', 'min:1', 'max:60'],
            'allow_multiple_active_advances' => ['required', 'boolean'],
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
