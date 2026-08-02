<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ManagementOvertimeActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject'],
            'role_context' => ['required', 'string', 'in:manager,hr'],
            'hours_approved' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'review_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
