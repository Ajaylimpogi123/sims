<?php

use App\Http\Controllers\InternshipAssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:2,4'])->group(function () {
    Route::get('/internship-assignment', [InternshipAssignmentController::class, 'index'])
        ->name('internship-assignment.index');

    Route::patch('/internship-assignment/{student}', [InternshipAssignmentController::class, 'update'])
        ->name('internship-assignment.update');

    Route::patch('/internship-assignment/{student}/toggle-status', [InternshipAssignmentController::class, 'toggleStatus'])
        ->name('internship-assignment.toggle-status');
});
