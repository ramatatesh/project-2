<?php

namespace App\Contracts\Ai;

use App\Models\Employee;
use App\Models\User;

/**
 * Pluggable context source for the Employee AI Assistant.
 * Implementations must return only data the authenticated employee is allowed to see.
 */
interface EmployeeContextProvider
{
    /**
     * Stable key used in the sanitized context payload (e.g. "performance").
     */
    public function key(): string;

    /**
     * Whether this provider should contribute context for the given user message.
     */
    public function supports(string $message): bool;

    /**
     * Build a sanitized array for Gemini. Must be scoped to $employee / $user only.
     *
     * @return array<string, mixed>
     */
    public function build(Employee $employee, User $user): array;
}
