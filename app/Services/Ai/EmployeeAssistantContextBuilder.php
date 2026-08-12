<?php

namespace App\Services\Ai;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

class EmployeeAssistantContextBuilder
{
    /** @param Collection<int, EmployeeContextProvider>|iterable<EmployeeContextProvider> $providers */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * Build a sanitized, tenant-scoped context for Gemini.
     *
     * @return array{employee: array<string, mixed>, contexts: array<string, mixed>}
     */
    public function build(Employee $employee, User $user, string $message): array
    {
        $employee->loadMissing(['user', 'company', 'department']);

        $contexts = [];
        foreach ($this->providers as $provider) {
            if (! $provider instanceof EmployeeContextProvider) {
                continue;
            }

            if (! $provider->supports($message)) {
                continue;
            }

            $contexts[$provider->key()] = $provider->build($employee, $user);
        }

        return [
            'employee' => [
                'full_name' => $user->full_name,
                'job_title' => $employee->job_title,
                'department_name' => $employee->department?->name,
                'company_name' => $employee->company?->name,
            ],
            'contexts' => $contexts,
            'notes' => [
                'Only data in this payload may be used to answer.',
                'Missing context keys mean that topic was not loaded for this question or is unavailable.',
                'This assistant is personal to the authenticated employee; never answer about other employees.',
                'Relevant context keys may include: performance, leaves, attendance, salary, salary_advances, company_policies, company_holidays, company_profile.',
            ],
        ];
    }
}
