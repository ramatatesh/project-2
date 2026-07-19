<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreEvaluationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'is_archived' => ['nullable', 'boolean'],
            'questions' => ['nullable', 'array'],
            'questions.*.question' => ['required_with:questions', 'string'],
            'questions.*.response_type' => ['required_with:questions', 'string', 'in:rating,text'],
            'questions.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'questions.*.weight' => ['nullable', 'numeric', 'min:0'],
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
