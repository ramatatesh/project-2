<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'leave_types' => ['required', 'array', 'min:1'],
            'leave_types.*.id' => ['sometimes', 'string', 'uuid'],
            'leave_types.*.name' => ['required', 'string', 'max:255'],
            'leave_types.*.allocation_value' => ['required', 'integer', 'min:0'],
            'leave_types.*.allocation_unit' => ['required', 'string', 'in:days,hours'],
            'leave_types.*.requires_proof' => ['sometimes', 'boolean'],
            'leave_types.*.is_active' => ['sometimes', 'boolean'],
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
