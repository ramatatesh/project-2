<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'The email address format is invalid. Example: name@company.com',
            'email.max' => 'The email address is too long.',
            'password.required' => 'Please enter your password.',
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
