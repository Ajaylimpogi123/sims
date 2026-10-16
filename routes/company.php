<?php

use App\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:2,4'])->group(function () {
    Route::get('/company-management', [CompanyController::class, 'index'])
        ->name('company-management.index');

    Route::post('/company-management', [CompanyController::class, 'store'])
        ->name('company-management.store');

    Route::patch('/company-management/{company}', [CompanyController::class, 'update'])
        ->name('company-management.update');

    Route::patch('/company-management/{company}/toggle-status', [CompanyController::class, 'toggleStatus'])
        ->name('company-management.toggle-status');

    Route::delete('/company-management/{company}', [CompanyController::class, 'destroy'])
        ->name('company-management.destroy');
});
