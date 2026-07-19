<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class EmployeeLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $leaveTypeId = $this->input('leave_type_id');

        return [
            'leave_type_id' => [
                'required',
                'string',
                'uuid',
                function ($attribute, $value, $fail) use ($companyId) {
                    $leaveType = LeaveType::where('id', $value)
                        ->where('company_id', $companyId)
                        ->first();

                    if (! $leaveType) {
                        $fail('The selected leave type is invalid or does not belong to your company.');

                        return;
                    }

                    if (! $leaveType->is_active) {
                        $fail('The selected leave type is not active.');
                    }
                },
            ],
            'duration_type' => ['required', 'string', 'in:single_day,multiple_days'],
            'start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'end_date' => [
                Rule::requiredIf($this->input('duration_type') === 'multiple_days'),
                'nullable',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'attachment' => [
                Rule::requiredIf(function () use ($companyId, $leaveTypeId) {
                    if (! $leaveTypeId) {
                        return false;
                    }

                    $leaveType = LeaveType::where('id', $leaveTypeId)
                        ->where('company_id', $companyId)
                        ->first();

                    return $leaveType && $leaveType->requires_proof;
                }),
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
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
