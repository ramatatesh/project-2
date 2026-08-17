<?php

namespace App\Http\Requests\Auth;

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
        $errors = $validator->errors();
        $message = collect($errors->all())->unique()->implode(' ');

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422));
    }
}
