<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API (v1)
|--------------------------------------------------------------------------
|
| JSON API for the SIMS mobile app (Students and Supervisors only).
| Loaded by bootstrap/app.php with the "api" prefix and middleware group;
| the contract is documented in docs/api/v1.md.
|
| Authenticated routes: Bearer token (auth:sanctum) + "mobile", which
| rejects (and revokes the token of) anyone who is no longer an active
| Student/Supervisor. Narrow further per module with role:1 / role:3.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:api-login')
        ->name('login');

    Route::middleware(['auth:sanctum', 'mobile', 'throttle:api'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});
