<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/admin-dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'role:4'])
    ->name('admin-dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
require __DIR__.'/user.php';
require __DIR__.'/company.php';
require __DIR__.'/student.php';
require __DIR__.'/internship-assignment.php';
require __DIR__.'/attendance.php';
require __DIR__.'/attendance-monitoring.php';
require __DIR__.'/attendance-approvals.php';
require __DIR__.'/internship-reports.php';
require __DIR__.'/report-reviews.php';
require __DIR__.'/progress-monitoring.php';
require __DIR__.'/notifications.php';
