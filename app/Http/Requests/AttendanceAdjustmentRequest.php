<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AttendanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_check_in' => ['nullable', 'date'],
            'new_check_out' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['new_check_in', 'new_check_out'])) {
                return;
            }

            if (blank($this->input('new_check_in')) && blank($this->input('new_check_out'))) {
                $validator->errors()->add('new_check_in', 'At least one of new_check_in or new_check_out must be provided.');

                return;
            }

            $record = $this->route('attendanceRecord');

            $effectiveCheckIn = filled($this->input('new_check_in'))
                ? strtotime($this->input('new_check_in'))
                : ($record && $record->check_in_time ? $record->check_in_time->timestamp : null);

            $effectiveCheckOut = filled($this->input('new_check_out'))
                ? strtotime($this->input('new_check_out'))
                : ($record && $record->check_out_time ? $record->check_out_time->timestamp : null);

            if ($effectiveCheckIn === null && $effectiveCheckOut !== null) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'لا يمكن تسجيل وقت الانصراف بدون وجود تسجيل دخول لهذا اليوم.',
                ], 422));
            }

            if ($effectiveCheckIn !== null && $effectiveCheckOut !== null && $effectiveCheckOut <= $effectiveCheckIn) {
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
