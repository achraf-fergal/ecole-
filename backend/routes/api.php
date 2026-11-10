
<?php

use App\Http\Controllers\Api\PublicCourseController;
use App\Http\Controllers\Api\PublicCourseDetailsController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/centers/{center:slug}')
    ->group(function () {

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
