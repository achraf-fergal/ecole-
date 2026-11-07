<?php

use App\Http\Controllers\Api\PublicCourseController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/centers/{center:slug}')
    ->group(function () {
        Route::get('/courses', [PublicCourseController::class, 'index'])
            ->name('api.public.centers.courses.index');
    });