<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
{
    return [
        'email' => [
            'required',
            'email'
        ],

        'password' => [
          'required',
          'confirmed',
          \Illuminate\Validation\Rules\Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
        ],
    ];
}

    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.email' => 'Please provide a valid email address.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.letters' => 'Password must contain at least one letter.',
            'password.mixed' => 'Password must contain both uppercase and lowercase letters.',
            'password.numbers' => 'Password must contain at least one number.',
            'password.symbols' => 'Password must contain at least one special character.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $this->passwordValidationMessage($validator),
            'errors' => $validator->errors(),
        ], 422));
    }

    private function passwordValidationMessage(Validator $validator): string
    {
        $passwordErrors = $validator->errors()->get('password', []);

        if ($passwordErrors !== []) {
            return implode(' ', $passwordErrors);
        }

        return $validator->errors()->first()
            ?: 'Password must be strong and include: at least 8 characters, an uppercase and lowercase letter, a number, and a special character (such as @ # $ %).';
    }
}
