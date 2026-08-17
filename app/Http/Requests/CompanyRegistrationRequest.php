<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CompanyRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:companies,email'],
            'domain' => ['nullable', 'string', 'max:255', 'unique:companies,domain'],
            'address' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^09[0-9]{8}$/'],
            'plan_id' => ['required', 'string', 'exists:subscription_plans,id'],
         //   'payment_status' => ['nullable', 'in:paid,pending'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'in:single,married,divorced,widowed'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'residence' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone number must start with 09 and contain 10 digits.',
            'email.unique' => 'This email is already used by another company.',
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
