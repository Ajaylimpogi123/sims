<?php

use App\Http\Controllers\ReportReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:2,3,4'])->group(function () {
    Route::get('/report-reviews', [ReportReviewController::class, 'index'])
        ->name('report-reviews.index');
});

// Supervisor gets read-only access above — submitting a review is
// Coordinator/Admin only.
Route::middleware(['auth', 'role:2,4'])->group(function () {
    Route::patch('/report-reviews/{report}', [ReportReviewController::class, 'review'])
        ->name('report-reviews.review');
});
