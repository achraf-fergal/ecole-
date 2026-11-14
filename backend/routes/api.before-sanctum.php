
<?php

use App\Http\Controllers\Api\PublicCourseController;
use App\Http\Controllers\Api\PublicCourseDetailsController;
use App\Http\Controllers\Api\PublicLeadController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/centers/{center:slug}')
    ->group(function () {
        // Public course registration request.
        Route::post('/courses/{course:slug}/leads', [
            PublicLeadController::class,
            'store',
        ])
            ->scopeBindings()
            ->middleware('throttle:10,1')
            ->name('api.public.centers.courses.leads.store');

        // Public courses listing
        Route::get('/courses', [
            PublicCourseController::class,
            'index',
        ])->name('api.public.centers.courses.index');

        // Public course details
        Route::get('/courses/{course:slug}', [
            PublicCourseDetailsController::class,
            '__invoke',
        ])
            ->scopeBindings()
            ->name('api.public.centers.courses.show');
    });
