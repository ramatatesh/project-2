<?php

use Illuminate\Auth\AuthenticationException;
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
