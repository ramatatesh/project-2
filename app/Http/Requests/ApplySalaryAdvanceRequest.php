<?php

namespace App\Http\Requests;

use App\Models\SalaryAdvancePolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ApplySalaryAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        $policy = SalaryAdvancePolicy::where('company_id', $companyId)->first();
        $maxRepaymentMonths = $policy?->max_repayment_months ?? 12;

        return [
            'requested_amount' => ['required', 'numeric', 'min:1'],
            'repayment_months' => ['required', 'integer', 'min:1', "max:{$maxRepaymentMonths}"],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
