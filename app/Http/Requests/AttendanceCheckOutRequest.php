<?php

namespace App\Http\Requests;

use App\Models\AttendancePolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AttendanceCheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $policy = AttendancePolicy::where('company_id', $companyId)->first();
        $gpsRequired = (bool) $policy?->enable_gps_verification;

        return [
            'qr_token' => ['required', 'string'],
            'latitude' => [$gpsRequired ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [$gpsRequired ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
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
