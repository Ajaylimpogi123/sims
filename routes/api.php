<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendancePhotoController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Middleware\ThrottleFailedAuth;
use App\Http\Middleware\ThrottleLogin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API (v1)
|--------------------------------------------------------------------------
|
| JSON API for the SIMS mobile app (all four roles).
| Loaded by bootstrap/app.php with the "api" prefix and middleware group;
| the contract is documented in docs/api/v1.md.
|
| Authenticated routes: Bearer token (auth:sanctum) + "mobile", which
| rejects (and revokes the token of) anyone who is no longer active or has
| no SIMS role. Narrow further per module with the role: middleware (e.g. role:3,4).
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware(ThrottleLogin::class)
        ->name('login');

    // ThrottleFailedAuth must come before auth:sanctum: it caps 401s per IP.
    Route::middleware([ThrottleFailedAuth::class, 'auth:sanctum', 'mobile', 'throttle:api'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        // Notifications: every role, own inbox only.
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->whereNumber('notification')
            ->name('notifications.read');

        // Dashboard: every role, own role's summary (Supervisor scoped to
        // their own students inside DashboardAnalyticsService).
        Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');

        // Analytics: the website's Analytics tab is Coordinator, Supervisor
        // and Admin only.
        Route::middleware('role:2,3,4')->group(function () {
            Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
            Route::get('analytics/filter-options', [AnalyticsController::class, 'filterOptions'])->name('analytics.filter-options');
        });

        // My Attendance: Student only, always the signed-in student's own
        // record (no ids accepted). Rules live in AttendanceService.
        Route::middleware('role:1')->group(function () {
            Route::get('attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
            Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            Route::post('attendance/time-in', [AttendanceController::class, 'timeIn'])->name('attendance.time-in');
            Route::post('attendance/time-out', [AttendanceController::class, 'timeOut'])->name('attendance.time-out');
            Route::post('attendance/emergency-time-out', [AttendanceController::class, 'emergencyTimeOut'])->name('attendance.emergency-time-out');
        });

        // Evidence photos: every role, scoped per record by
        // AttendancePolicy::view inside the controller (404 when not visible).
        Route::get('attendance/{attendance}/photo/{leg}', AttendancePhotoController::class)
            ->whereNumber('attendance')
            ->whereIn('leg', ['time_in', 'time_out'])
            ->name('attendance.photo');
    });
});
