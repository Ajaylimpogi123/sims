<?php

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:2,4'])->group(function () {
    Route::get('/user-management', [UserController::class, 'index'])
        ->name('user-management.index');

    Route::post('/user-management/create', [RegisteredUserController::class, 'store'])
        ->name('user-management.store');

    Route::patch('/user-management/{id}', [UserController::class, 'update'])
        ->where('id', '[1-9][0-9]{0,17}')
        ->name('user-management.update');

    Route::patch('/user-management/{id}/toggle-status', [UserController::class, 'toggleStatus'])
        ->where('id', '[1-9][0-9]{0,17}')
        ->name('user-management.toggle-status');
});
