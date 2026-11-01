<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\CourseController;

Route::prefix('api')->name('api.')->group(function (): void {
    Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
    Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
    Route::get('courses/{course}', [CourseController::class, 'show'])->name('courses.show');
    Route::delete('courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');
});
