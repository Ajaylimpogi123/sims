<?php

use App\Http\Controllers\AttendanceApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:3,4'])->group(function () {
    Route::get('/attendance-approvals', [AttendanceApprovalController::class, 'index'])
        ->name('attendance-approvals.index');

    Route::patch('/attendance-approvals/{attendance}/approve-time-in', [AttendanceApprovalController::class, 'approveTimeIn'])
        ->name('attendance-approvals.approve-time-in');

    Route::patch('/attendance-approvals/{attendance}/reject-time-in', [AttendanceApprovalController::class, 'rejectTimeIn'])
        ->name('attendance-approvals.reject-time-in');

    Route::patch('/attendance-approvals/{attendance}/approve-time-out', [AttendanceApprovalController::class, 'approveTimeOut'])
        ->name('attendance-approvals.approve-time-out');

    Route::patch('/attendance-approvals/{attendance}/reject-time-out', [AttendanceApprovalController::class, 'rejectTimeOut'])
        ->name('attendance-approvals.reject-time-out');
});
