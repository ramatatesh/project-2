<?php

namespace App\Http\Requests;

use App\Models\EmployeeDocument;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UploadProfileDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Queried fresh (not via the cached $user->employee->document relation) so this is
        // always correct even when the same request-scoped models are reused across calls.
        $employeeId = $this->user()?->employee?->id;
        $hasIdentity = $employeeId
            && filled(EmployeeDocument::where('employee_id', $employeeId)->value('identity_image_path'));

        $identityRules = array_merge(
            $hasIdentity ? ['sometimes', 'nullable'] : ['required'],
            ['file', 'mimes:jpg,jpeg,png,pdf', 'max:4096']
        );

        return [
            'identity_image' => $identityRules,
            'university_certificate' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasFile('identity_image') && ! $this->hasFile('university_certificate')) {
                $validator->errors()->add('identity_image', 'At least one document file must be provided.');
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
