<?php

namespace App\Services\Ai\Context;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Ai\Concerns\MatchesAssistantTopics;

/**
 * Company profile text fields for the authenticated employee's company
 * (same as GET /api/company/profile, without logo binary).
 */
class CompanyProfileContextProvider implements EmployeeContextProvider
{
    use MatchesAssistantTopics;

    public function key(): string
    {
        return 'company_profile';
    }

    public function supports(string $message): bool
    {
        return $this->matchesAny($message, [
            'اسم الشركة', 'نبذة', 'عن الشركة', 'مجال الشركة',
            'رقم الشركة', 'إيميل الشركة', 'ايميل الشركة', 'عنوان الشركة',
            'تواصل', 'معلومات التواصل', 'company profile', 'about the company',
            'tagline', 'company phone', 'company email', 'company address',
            'شو اسم', 'ما اسم الشركة',
        ]);
    }

    public function build(Employee $employee, User $user): array
    {
        $company = Company::find($employee->company_id);

        if (! $company) {
            return [
                'available' => false,
                'profile' => null,
            ];
        }

        return [
            'available' => true,
            'profile' => [
                'name' => $company->name,
                'tagline' => $company->tagline,
                'about' => $company->about,
                'phone' => $company->phone,
                'email' => $company->email,
                'address' => $company->address,
                'has_logo' => filled($company->logo_path),
            ],
            'notes' => [
                'Logo binary/URL is omitted from AI context; only textual profile fields are included.',
            ],
        ];
    }
}
