<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use App\Services\LeaveAttachmentService;
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

    protected function prepareForValidation(): void
    {
        // Excel-style field name `file` maps to the leave attachment upload.
        if ($this->hasFile('file') && ! $this->hasFile('attachment')) {
            $this->files->set('attachment', $this->file('file'));
        }
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

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
            'start_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:today'],
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
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'attachment_path' => [
                'nullable',
                'string',
                'max:500',
                function ($attribute, $value, $fail) {
                    if (blank($value)) {
                        return;
                    }

                    if (! app(LeaveAttachmentService::class)->isOwnedPath((string) $value)) {
                        $fail('The attachment_path is invalid or the file was not found. Upload via /api/employee/leaves/upload-attachment first.');
                    }
                },
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->user()?->company_id;
            $leaveTypeId = $this->input('leave_type_id');

            if (! $leaveTypeId || ! $companyId) {
                return;
            }

            $leaveType = LeaveType::where('id', $leaveTypeId)
                ->where('company_id', $companyId)
                ->first();

            if (! $leaveType?->requires_proof) {
                return;
            }

            $hasUpload = $this->hasFile('file') || $this->hasFile('attachment');
            $hasPath = filled($this->input('attachment_path'));

            if (! $hasUpload && ! $hasPath) {
                $validator->errors()->add(
                    'file',
                    'A proof file is required for this leave type. Upload with field "file" (multipart), same as Excel import.'
                );
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
