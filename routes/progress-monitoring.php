<?php

use App\Http\Controllers\ProgressMonitoringController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:2,3,4'])->group(function () {
    Route::get('/progress-monitoring', [ProgressMonitoringController::class, 'index'])
        ->name('progress-monitoring.index');
});
