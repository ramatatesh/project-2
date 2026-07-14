<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $role = Role::tryFrom($user->role);

        if ($role && $role->isSuperAdmin()) {
            return $next($request);
        }

        $company = $request->route('company');

        if (! $company instanceof Company) {
            abort(500, 'Tenant middleware requires a bound company route parameter.');
        }

        if ($user->company_id !== $company->id) {
            abort(403, 'You do not have access to this company.');
        }

        return $next($request);
    }
}
