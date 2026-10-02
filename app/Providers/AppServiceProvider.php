<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->configureApiRateLimiting();
    }

    /**
     * Named rate limiters for the mobile API (routes/api.php). Defined here,
     * not in the routing callback, so they still exist when routes are cached.
     */
    protected function configureApiRateLimiting(): void
    {
        // Mobile login: 5 attempts per minute per email + IP.
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(5)->by(
            Str::transliterate(Str::lower((string) $request->input('email'))).'|'.$request->ip()
        ));

        // General ceiling for authenticated mobile API calls.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            (string) ($request->user()?->id ?: $request->ip())
        ));
    }

    /**
     * Share common data with Inertia.
     */
    protected function shareInertiaData(): void
    {
        Inertia::share([
            'auth' => function () {
                $user = auth()->user(); // ✅ Store user once

                return [
                    'user' => $user ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar' => $user->avatar ?? '/images/logo/Westpoint.png',
                    ] : null,
                ];
            },

            'flash' => function () {
                return [
                    'success' => session('success'),
                    'error' => session('error'),
                ];
            },
        ]);
    }
}
