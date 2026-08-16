<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AttendanceManualRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'work_date' => ['nullable', 'date'],
            'check_in_time' => ['required', 'date'],
            'check_out_time' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $checkIn = strtotime((string) $this->input('check_in_time'));
            $checkOut = $this->filled('check_out_time')
                ? strtotime((string) $this->input('check_out_time'))
                : null;

            if ($checkOut !== null && $checkOut <= $checkIn) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'وقت الانصراف يجب أن يكون بعد وقت الدخول.',
                ], 422));
            }
        });
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
