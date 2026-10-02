<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendancePhotoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:1'])->group(function () {
    Route::get('/my-attendance', [AttendanceController::class, 'index'])
        ->name('attendance.index');

    Route::post('/my-attendance/time-in', [AttendanceController::class, 'timeIn'])
        ->name('attendance.time-in');

    Route::post('/my-attendance/time-out', [AttendanceController::class, 'timeOut'])
        ->name('attendance.time-out');

    Route::post('/my-attendance/emergency-time-out', [AttendanceController::class, 'emergencyTimeOut'])
        ->name('attendance.emergency-time-out');
});

// Evidence photos are viewable by every role, but scoped per record inside
// AttendancePhotoController (student = own, supervisor = own students).
Route::middleware(['auth', 'role:1,2,3,4'])->group(function () {
    Route::get('/attendance/{attendance}/photo/{leg}', AttendancePhotoController::class)
        ->whereIn('leg', ['time_in', 'time_out'])
        ->name('attendance.photo');
});
