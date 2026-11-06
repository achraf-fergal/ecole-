<?php

use App\\Http\\Controllers\\Api\\PublicCourseController;
use Illuminate\\Support\\Facades\\Route;

Route::get(
    '/public/centers/{center:slug}/courses',
    PublicCourseController::class
)->name('api.public.centers.courses');
