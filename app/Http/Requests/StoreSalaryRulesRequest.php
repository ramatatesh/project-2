<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSalaryRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_currency' => ['required', 'string', 'max:3'],
            'absence_day_deduction_percent' => ['required', 'numeric', 'min:0'],
            'unpaid_leave_day_percent' => ['required', 'numeric', 'min:0'],
            'late_arrival_deduction_percent' => ['required', 'numeric', 'min:0'],
            'early_departure_deduction_percent' => ['required', 'numeric', 'min:0'],
            'overtime_hour_rate_percent' => ['required', 'numeric', 'min:0'],
            'overtime_day_rate_percent' => ['required', 'numeric', 'min:0'],
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
