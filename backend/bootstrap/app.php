<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnsureCenterRole;
use App\Http\Middleware\EnsureCourseEnrollment;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\ValidateApiCsrf;
use App\Http\Middleware\ValidateTenantAccess;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->priority([
            ValidateApiCsrf::class,
            AuthenticateJwt::class,
            EnsureEmailIsVerified::class,
            ValidateTenantAccess::class,
            EnsureCenterRole::class,
            EnsureCourseEnrollment::class,
        ]);

        $middleware->alias([
            'auth.jwt' => AuthenticateJwt::class,
            'verified.api' => EnsureEmailIsVerified::class,
            'csrf.api' => ValidateApiCsrf::class,
            'tenant' => ValidateTenantAccess::class,
            'role' => EnsureCenterRole::class,
            'enrollment' => EnsureCourseEnrollment::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->create();
