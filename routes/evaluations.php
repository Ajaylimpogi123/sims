<?php

use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\EvaluationCriteriaController;
use Illuminate\Support\Facades\Route;

// Coordinator gets read-only access — creating/editing/submitting an
// evaluation is Supervisor/Admin only, matching the ReportReviews and
// AttendanceMonitoring pattern.
Route::middleware(['auth', 'role:2,3,4'])->group(function () {
    Route::get('/supervisor-evaluations', [EvaluationController::class, 'index'])
        ->name('supervisor-evaluations.index');

    Route::get('/supervisor-evaluations/{evaluation}', [EvaluationController::class, 'show'])
        ->name('supervisor-evaluations.show');
});

Route::middleware(['auth', 'role:3,4'])->group(function () {
    Route::post('/supervisor-evaluations', [EvaluationController::class, 'store'])
        ->name('supervisor-evaluations.store');

    Route::patch('/supervisor-evaluations/{evaluation}', [EvaluationController::class, 'update'])
        ->name('supervisor-evaluations.update');

    Route::patch('/supervisor-evaluations/{evaluation}/submit', [EvaluationController::class, 'submit'])
        ->name('supervisor-evaluations.submit');
});

Route::middleware(['auth', 'role:4'])->group(function () {
    Route::patch('/supervisor-evaluations/{evaluation}/lock', [EvaluationController::class, 'lock'])
        ->name('supervisor-evaluations.lock');

    Route::patch('/supervisor-evaluations/{evaluation}/reopen', [EvaluationController::class, 'reopen'])
        ->name('supervisor-evaluations.reopen');

    Route::get('/evaluation-criteria', [EvaluationCriteriaController::class, 'index'])
        ->name('evaluation-criteria.index');

    Route::post('/evaluation-criteria', [EvaluationCriteriaController::class, 'store'])
        ->name('evaluation-criteria.store');

    Route::patch('/evaluation-criteria/{evaluationCriterion}', [EvaluationCriteriaController::class, 'update'])
        ->name('evaluation-criteria.update');

    Route::patch('/evaluation-criteria/{evaluationCriterion}/toggle-active', [EvaluationCriteriaController::class, 'toggleActive'])
        ->name('evaluation-criteria.toggle-active');

    Route::delete('/evaluation-criteria/{evaluationCriterion}', [EvaluationCriteriaController::class, 'destroy'])
        ->name('evaluation-criteria.destroy');
});

Route::middleware(['auth', 'role:1'])->group(function () {
    Route::get('/my-feedback', [EvaluationController::class, 'myFeedback'])
        ->name('my-feedback.index');
});
