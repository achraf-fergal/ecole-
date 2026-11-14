<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Learning\LearningLessonController;
use App\Http\Controllers\Api\Learning\LearningOverviewController;
use App\Http\Controllers\Api\PlatformUserController;
use App\Http\Controllers\Api\PublicCourseController;
use App\Http\Controllers\Api\PublicCourseDetailsController;
use App\Http\Controllers\Api\PublicLeadController;
use App\Http\Controllers\Api\TenantUserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::prefix('public/centers/{center:slug}')->group(function () {
    // Public course registration request.
    Route::post('/courses/{course:slug}/leads', [
        PublicLeadController::class,
        'store',
    ])
        ->scopeBindings()
        ->middleware('throttle:10,1')
        ->name('api.public.centers.courses.leads.store');

    // Public courses listing.
    Route::get('/courses', [
        PublicCourseController::class,
        'index',
    ])->name('api.public.centers.courses.index');

    // Public course details.
    Route::get('/courses/{course:slug}', [
        PublicCourseDetailsController::class,
        '__invoke',
    ])
        ->scopeBindings()
        ->name('api.public.centers.courses.show');
});

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    // CSRF cookie for browser state-changing requests.
    Route::get('/csrf-cookie', [AuthController::class, 'csrfCookie'])
        ->name('api.auth.csrf-cookie');

    // Public auth endpoints.
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware(['throttle:10,1', 'csrf.api'])
        ->name('api.auth.register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware(['csrf.api'])
        ->name('api.auth.login');

    // Google OAuth endpoints.
    Route::get('/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->name('api.auth.google.redirect');

    Route::get('/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('api.auth.google.callback');

    Route::post('/forgot-password', [
        PasswordResetController::class,
        'forgotPassword',
    ])
        ->middleware(['throttle:6,1', 'csrf.api'])
        ->name('api.auth.forgot-password');

    Route::post('/reset-password', [
        PasswordResetController::class,
        'resetPassword',
    ])
        ->middleware(['throttle:6,1', 'csrf.api'])
        ->name('api.auth.reset-password');

    // Email verification via signed link.
    Route::get('/email/verify/{id}/{hash}', [
        EmailVerificationController::class,
        'verify',
    ])->name('verification.verify');

    // Authenticated auth endpoints.
    Route::middleware(['csrf.api', 'auth.jwt'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])
            ->name('api.auth.me');

        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('api.auth.logout');

        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->name('api.auth.refresh');

        Route::post('/logout-all', [AuthController::class, 'logoutAll'])
            ->name('api.auth.logout-all');

        Route::post('/email/verification-notification', [
            EmailVerificationController::class,
            'resend',
        ])
            ->middleware('throttle:6,1')
            ->name('api.auth.email.verification-notification');
    });
});

/*
|--------------------------------------------------------------------------
| Protected Tenant & Role-Based Routes
|--------------------------------------------------------------------------
*/

Route::prefix('centers/{center}')
    ->middleware(['auth.jwt', 'verified.api', 'tenant'])
    ->group(function () {
        // Center home / membership validation.
        Route::get('/context', function (Request $request) {
            return response()->json([
                'center' => $request->attributes->get('current_center'),
                'membership' => $request->attributes->get('current_membership'),
            ]);
        })->name('api.centers.context');

        // Center Admin area.
        Route::prefix('admin')
            ->middleware('role:center_admin')
            ->group(function () {
                Route::get('/dashboard', function (Request $request) {
                    return response()->json([
                        'message' => 'Center admin access granted.',
                        'center_id' => $request->attributes->get('current_center')->id,
                    ]);
                })->name('api.centers.admin.dashboard');

                // User & Role Management within this center.
                Route::get('/roles', [TenantUserController::class, 'availableRoles'])
                    ->name('api.tenant.roles');

                Route::get('/users', [TenantUserController::class, 'index'])
                    ->name('api.tenant.users.index');

                Route::post('/users', [TenantUserController::class, 'store'])
                    ->middleware('csrf.api')
                    ->name('api.tenant.users.store');

                Route::put('/users/{user}/roles', [
                    TenantUserController::class,
                    'updateRoles',
                ])
                    ->middleware('csrf.api')
                    ->name('api.tenant.users.roles.update');

                Route::put('/users/{user}/status', [
                    TenantUserController::class,
                    'updateStatus',
                ])
                    ->middleware('csrf.api')
                    ->name('api.tenant.users.status.update');

                Route::delete('/users/{user}', [
                    TenantUserController::class,
                    'destroy',
                ])
                    ->middleware('csrf.api')
                    ->name('api.tenant.users.destroy');
            });

        // Teacher area.
        Route::prefix('teacher')
            ->middleware('role:teacher')
            ->group(function () {
                Route::get('/cohorts', function (Request $request) {
                    return response()->json([
                        'message' => 'Teacher access granted.',
                    ]);
                })->name('api.centers.teacher.cohorts');
            });

        /*
        |--------------------------------------------------------------------------
        | Student Learning API
        |--------------------------------------------------------------------------
        |
        | Every endpoint here requires:
        | - Authenticated JWT
        | - Verified email
        | - Active tenant access
        | - Student role
        | - Active course enrollment
        |
        */

        Route::prefix('courses/{course}/learn')
            ->middleware(['role:student', 'enrollment'])
            ->group(function () {
                // Course learning overview.
                Route::get('/', LearningOverviewController::class)
                    ->name('api.centers.courses.learn.overview');

                // Retrieve one published lesson.
                Route::get('/lessons/{lesson}', [
                    LearningLessonController::class,
                    'show',
                ])->name('api.centers.courses.learn.lessons.show');

                // Save lesson progress.
                Route::patch('/lessons/{lesson}/progress', [
                    LearningLessonController::class,
                    'updateProgress',
                ])
                    ->middleware('csrf.api')
                    ->name('api.centers.courses.learn.lessons.progress.update');
            });
    });

/*
|--------------------------------------------------------------------------
| Platform Super Admin area
|--------------------------------------------------------------------------
*/

Route::prefix('platform-admin')
    ->middleware(['auth.jwt', 'verified.api', 'platform.admin'])
    ->group(function () {
        Route::get('/stats', function () {
            return response()->json([
                'message' => 'Platform admin access granted.',
            ]);
        })->name('api.platform-admin.stats');

        Route::get('/users', [PlatformUserController::class, 'index'])
            ->name('api.platform.users.index');

        Route::put('/users/{user}/super-admin', [
            PlatformUserController::class,
            'toggleSuperAdmin',
        ])
            ->middleware('csrf.api')
            ->name('api.platform.users.super-admin');

        Route::put('/users/{user}/status', [
            PlatformUserController::class,
            'updateStatus',
        ])
            ->middleware('csrf.api')
            ->name('api.platform.users.status');

        Route::post('/users/{user}/centers', [
            PlatformUserController::class,
            'assignCenter',
        ])
            ->middleware('csrf.api')
            ->name('api.platform.users.assign-center');
    });

