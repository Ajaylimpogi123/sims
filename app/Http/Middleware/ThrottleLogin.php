<?php

namespace App\Http\Middleware;

use App\Services\LoginThrottleKey;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login rate limit for POST /api/v1/login. Counts in the same RateLimiter
 * bucket as the website's LoginRequest (LoginThrottleKey), so web and app
 * share one allowance of LoginThrottleKey::MAX_ATTEMPTS per minute per
 * account + IP. Every API attempt counts, successful or not; the website
 * counts its failures and clears the bucket on a successful login.
 */
class ThrottleLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = LoginThrottleKey::for($request->input('email'), $request->ip());

        if (RateLimiter::tooManyAttempts($key, LoginThrottleKey::MAX_ATTEMPTS)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => LoginThrottleKey::MAX_ATTEMPTS,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
            ]);
        }

        RateLimiter::hit($key, LoginThrottleKey::DECAY_SECONDS);

        $response = $next($request);

        $response->headers->add([
            'X-RateLimit-Limit' => LoginThrottleKey::MAX_ATTEMPTS,
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, LoginThrottleKey::MAX_ATTEMPTS),
        ]);

        return $response;
    }
}
