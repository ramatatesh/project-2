<?php

namespace App\Providers;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Services\Ai\Context\AttendanceContextProvider;
use App\Services\Ai\Context\CompanyHolidayContextProvider;
use App\Services\Ai\Context\CompanyPolicyContextProvider;
use App\Services\Ai\Context\CompanyProfileContextProvider;
use App\Services\Ai\Context\LeaveContextProvider;
use App\Services\Ai\Context\PerformanceEvaluationContextProvider;
use App\Services\Ai\Context\SalaryAdvanceContextProvider;
use App\Services\Ai\Context\SalaryContextProvider;
use App\Services\Ai\EmployeeAssistantContextBuilder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([
            PerformanceEvaluationContextProvider::class,
            LeaveContextProvider::class,
            AttendanceContextProvider::class,
            SalaryContextProvider::class,
            SalaryAdvanceContextProvider::class,
            CompanyPolicyContextProvider::class,
            CompanyHolidayContextProvider::class,
            CompanyProfileContextProvider::class,
        ], 'employee.ai.context_providers');

        $this->app->bind(EmployeeAssistantContextBuilder::class, function ($app) {
            /** @var iterable<EmployeeContextProvider> $providers */
            $providers = $app->tagged('employee.ai.context_providers');

            return new EmployeeAssistantContextBuilder($providers);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'production') {
            \URL::forceScheme('https');
        }

        RateLimiter::for('employee-assistant', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(20)->by('employee-assistant:'.$key);
        });
    }
}
