<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Runs shortly after midnight for the PREVIOUS day rather than at a fixed
        // "end of workday" time, because each company can configure its own
        // work_end_time in AttendancePolicy - there is no single fixed hour that
        // safely covers every tenant's shift before midnight. Running at 01:00 for
        // "yesterday" guarantees every company's work day (including late shifts)
        // has fully ended before an employee is marked absent, while still keeping
        // attendance data ready the same day for payroll.
        $schedule->command('attendance:mark-absent')->dailyAt('01:00');

        // Annual leave balance renewal: create leave_balances rows for the new year
        // for every active employee × active leave type (skipped if already present).
        $schedule->command('leaves:renew-balances')->yearlyOn(1, 1, '00:30');

        // Expire pending evaluation reviews whose due_date (end of day) has passed.
        // Hourly so status flips soon after the deadline without waiting until next midnight.
        $schedule->command('evaluations:expire-pending-reviews')->hourly();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\RequireRole::class,
            'tenant' => \App\Http\Middleware\EnsureTenantMember::class,
            'webhook' => \App\Http\Middleware\VerifyWebhookSignature::class,
        ]);

        // المشروع API-only ولا يوجد route اسمه login،
        // لذا نُلغي إعادة التوجيه الافتراضية لتجنب RouteNotFoundException (500).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // إرجاع استجابة JSON موحّدة (401) لطلبات الـ API عند فشل المصادقة
        // بدل محاولة redirect إلى route('login') غير الموجود.
        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();
