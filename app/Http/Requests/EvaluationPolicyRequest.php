<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class EvaluationPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'apply_review_to_salary' => ['required', 'boolean'],
            'excellent_bonus_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'good_bonus_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'poor_deduction_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // 'manager_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // 'self_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // 'peer_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'peer_reviews_count' => ['nullable', 'integer', 'min:0'],
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
