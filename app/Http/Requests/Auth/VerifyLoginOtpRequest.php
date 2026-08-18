<?php

namespace App\Http\Requests\Auth;

use App\Support\ValidationMessages;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyLoginOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:4'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'The email address format is invalid. Example: name@company.com',
            'otp.required' => 'Please enter the 4-digit verification code sent to your email.',
            'otp.digits' => 'The verification code must be exactly 4 digits.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => ValidationMessages::fromValidator($validator),
            'errors' => $validator->errors(),
        ], 422));
    }
}
