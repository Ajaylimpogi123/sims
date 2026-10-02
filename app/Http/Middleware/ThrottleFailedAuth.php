<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before auth:sanctum on authenticated API routes. Requests that end
 * in 401 (missing, invalid or revoked token) are counted per client IP;
 * past MAX_FAILURES a minute, every request from that IP gets 429 until the
 * window passes, without a token lookup. Successful requests are not
 * counted, so many app users behind one campus/carrier NAT are unaffected
 * unless something on that address is spraying bad tokens.
 */
class ThrottleFailedAuth
{
    public const MAX_FAILURES = 60;

    public const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-failed-auth:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
            ]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }

        return $response;
    }
}
