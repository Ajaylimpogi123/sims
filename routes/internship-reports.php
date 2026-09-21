<?php

use App\Http\Controllers\InternshipReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:1'])->group(function () {
    Route::get('/my-reports', [InternshipReportController::class, 'index'])
        ->name('reports.index');

    Route::get('/my-reports/{report}/attachment', [InternshipReportController::class, 'downloadAttachment'])
        ->name('reports.attachment');

    Route::post('/my-reports', [InternshipReportController::class, 'store'])
        ->name('reports.store');

    Route::patch('/my-reports/{report}', [InternshipReportController::class, 'update'])
        ->name('reports.update');

    Route::delete('/my-reports/{report}', [InternshipReportController::class, 'destroy'])
        ->name('reports.destroy');
});
