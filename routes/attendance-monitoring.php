<?php

use App\Http\Controllers\AttendanceMonitoringController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:2,3,4'])->group(function () {
    Route::get('/attendance-monitoring', [AttendanceMonitoringController::class, 'index'])
        ->name('attendance-monitoring.index');
});

// Coordinator gets read-only access above — writes are Supervisor/Admin only.
Route::middleware(['auth', 'role:3,4'])->group(function () {
    Route::patch('/attendance-monitoring/{student}/required-hours', [AttendanceMonitoringController::class, 'updateRequiredHours'])
        ->name('attendance-monitoring.required-hours');

    Route::post('/attendance-monitoring/{student}/attendances', [AttendanceMonitoringController::class, 'store'])
        ->name('attendance-monitoring.attendances.store');

    Route::patch('/attendance-monitoring/attendances/{attendance}', [AttendanceMonitoringController::class, 'update'])
        ->name('attendance-monitoring.attendances.update');

    Route::delete('/attendance-monitoring/attendances/{attendance}', [AttendanceMonitoringController::class, 'destroy'])
        ->name('attendance-monitoring.attendances.destroy');
});
