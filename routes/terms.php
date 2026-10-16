<?php

use App\Http\Controllers\TermController;
use Illuminate\Support\Facades\Route;

// Term management: Coordinator and Administrator.
Route::middleware(['auth', 'role:2,4'])->group(function () {
    Route::get('/terms', [TermController::class, 'index'])->name('terms.index');
    Route::post('/terms', [TermController::class, 'store'])->name('terms.store');
    Route::patch('/terms/{term}', [TermController::class, 'update'])->name('terms.update');
    Route::delete('/terms/{term}', [TermController::class, 'destroy'])->name('terms.destroy');
    Route::post('/terms/{term}/current', [TermController::class, 'setCurrent'])->name('terms.set-current');
});

// The header term switcher: Coordinator, Supervisor and Administrator
// (a Student always sees their own term).
Route::middleware(['auth', 'role:2,3,4'])->group(function () {
    Route::post('/term/select', [TermController::class, 'select'])->name('term.select');
});
