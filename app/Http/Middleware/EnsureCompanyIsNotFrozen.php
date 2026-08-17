<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyIsNotFrozen
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

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }

        if ($this->isExemptWritePath($request)) {
            return $next($request);
        }

        $company = $request->route('company');
        if (! $company instanceof Company) {
            $company = $user->company_id ? Company::find($user->company_id) : null;
        }

        if ($company && $company->status === 'suspended') {
            Log::warning('Write blocked: company is frozen.', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'path' => $request->path(),
                'method' => $request->method(),
            ]);
            abort(403, 'Company is frozen.');
        }

        return $next($request);
    }

    private function isExemptWritePath(Request $request): bool
    {
        return $request->routeIs(
            'auth.*',
            'company.subscription.renew',
        );
    }
}
