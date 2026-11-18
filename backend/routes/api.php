<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Learning\LearningLessonController;
use App\Http\Controllers\Api\Learning\LearningOverviewController;
use App\Http\Controllers\Api\Learning\LessonAssetAccessController;
use App\Http\Controllers\Api\Learning\LessonAssetManagementController;
use App\Http\Controllers\Api\Learning\LessonContentController;
use App\Http\Controllers\Api\PlatformCenterController;
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

                /*
                |--------------------------------------------------------------------------
                | Lesson Content Management
                |--------------------------------------------------------------------------
                */

                Route::prefix('courses/{course}/lessons/{lesson}/content')
                    ->group(function () {

                        // Retrieve draft and published revisions.
                        Route::get('/', [
                            LessonContentController::class,
                            'show',
                        ])->name('api.centers.admin.learning.content.show');

                        // Create or retrieve the current draft.
                        Route::post('/revisions', [
                            LessonContentController::class,
                            'createDraft',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.content.revisions.store');

                        // Update current draft metadata.
                        Route::patch('/revisions/{revision}', [
                            LessonContentController::class,
                            'updateDraftMetadata',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.content.revisions.update');

                        // Add text.
                        Route::post('/revisions/{revision}/blocks/text', [
                            LessonContentController::class,
                            'storeTextBlock',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.content.blocks.text.store');

                        // Add media.
                        Route::post('/revisions/{revision}/blocks/media', [
                            LessonContentController::class,
                            'storeMediaBlock',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.content.blocks.media.store');

                        // Add exercise.
                        Route::post('/revisions/{revision}/blocks/exercise', [
                            LessonContentController::class,
                            'storeExerciseBlock',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.content.blocks.exercise.store');

                        // Reorder every block.
                        Route::put('/revisions/{revision}/blocks/order', [
                            LessonContentController::class,
                            'reorderBlocks',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.content.blocks.reorder');

                        // Update block content/settings.
                        Route::patch('/revisions/{revision}/blocks/{block}', [
                            LessonContentController::class,
                            'updateBlock',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.content.blocks.update');

                        // Delete block.
                        Route::delete('/revisions/{revision}/blocks/{block}', [
                            LessonContentController::class,
                            'deleteBlock',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.content.blocks.destroy');
                    });

                /*
                |--------------------------------------------------------------------------
                | Learning Asset Management
                |--------------------------------------------------------------------------
                |
                | These endpoints use the center tenant context and do not require
                | student enrollment.
                |
                */
                Route::prefix('courses/{course}/lessons/{lesson}/assets')
                    ->group(function () {
                        Route::get('/', [
                            LessonAssetManagementController::class,
                            'index',
                        ])->name('api.centers.admin.learning.assets.index');

                        Route::post('/', [
                            LessonAssetManagementController::class,
                            'store',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.assets.store');

                        Route::patch('/{asset}', [
                            LessonAssetManagementController::class,
                            'update',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.assets.update');

                        Route::patch('/{asset}/archive', [
                            LessonAssetManagementController::class,
                            'archive',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.assets.archive');

                        Route::patch('/{asset}/restore', [
                            LessonAssetManagementController::class,
                            'restore',
                        ])
                            ->middleware('csrf.api')
                            ->name('api.centers.admin.learning.assets.restore');
                    });
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
        /*
        |--------------------------------------------------------------------------
        | Learning Content Read Access
        |--------------------------------------------------------------------------
        |
        | Students:
        | - Must have an active enrollment.
        | - Can access published content only.
        |
        | Center Admins:
        | - Can preview all content in their center.
        |
        | Platform Super Admins:
        | - Can preview content across all centers.
        |
        */

        Route::prefix('courses/{course}/learn')->group(function () {

            // Read-only learning access.
            Route::middleware([
                'role:student,center_admin',
                'enrollment',
            ])->group(function () {

                Route::get('/', LearningOverviewController::class)
                    ->name('api.centers.courses.learn.overview');

                // Retrieve one authorized lesson.
                Route::get('/lessons/{lesson}', [
                    LearningLessonController::class,
                    'show',
                ])->name('api.centers.courses.learn.lessons.show');

                // Retrieve protected asset preview access.
                Route::get('/lessons/{lesson}/assets/{asset}/access', [
                    LessonAssetAccessController::class,
                    'show',
                ])
                    ->middleware('throttle:30,1')
                    ->name('api.centers.courses.learn.lessons.assets.access');

                // Download authorized assets.
                Route::get('/lessons/{lesson}/assets/{asset}/download', [
                    LessonAssetAccessController::class,
                    'download',
                ])
                    ->middleware('throttle:30,1')
                    ->name('api.centers.courses.learn.lessons.assets.download');
            });

            // Student-only progress updates.
            Route::patch('/lessons/{lesson}/progress', [
                LearningLessonController::class,
                'updateProgress',
            ])
                ->middleware([
                    'role:student',
                    'enrollment',
                    'csrf.api',
                ])
                ->name('api.centers.courses.learn.lessons.progress.update');
        });
        // Backward-compatible route used by the existing authentication test.
        Route::get('/player', function (Request $request) {
            return response()->json([
                'message' => 'Course player access granted.',
            ]);
        })
            ->middleware([
                'role:student',
                'enrollment',
            ])
            ->name('api.centers.courses.learn.player');
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

        // Global institute directory for Super Admin.
        Route::get('/centers', [
            PlatformCenterController::class,
            'index',
        ])->name('api.platform.centers.index');

        // All course states inside one selected institute.
        Route::get('/centers/{center:slug}/courses', [
            PlatformCenterController::class,
            'courses',
        ])->name('api.platform.centers.courses.index');
    });
