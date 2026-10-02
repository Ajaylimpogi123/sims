<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before auth:sanctum on authenticated API routes and limits, per
 * client IP, requests whose token can't be real: missing or malformed,
 * an unknown or revoked token id, or a wrong secret. Every such request
 * that ends in 401 counts; past MAX_FAILURES a minute that IP gets 429 for
 * them:
 *
 * - Missing / malformed tokens (not "<id>|<prefix><40 chars><crc32b>" with
 *   a matching checksum) are refused before any token-table query.
 * - Well-formed tokens are always looked up (one indexed query by id, done
 *   by Sanctum); if the lookup fails the 401 becomes a 429.
 *
 * A real token therefore never gets 429 from this limiter, however much
 * junk others on the same campus Wi-Fi / carrier NAT address send.
 */
class ThrottleFailedAuth
{
    public const MAX_FAILURES = 60;

    public const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-failed-auth:'.$request->ip();
        $overLimit = RateLimiter::tooManyAttempts($key, self::MAX_FAILURES);

        if ($overLimit && ! self::isWellFormed($request->bearerToken())) {
            $this->refuse($key);
        }

        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            if ($overLimit) {
                $this->refuse($key);
            }
        }

        return $response;
    }

    /**
     * Shape of a token issued by Sanctum 4 (HasApiTokens::createToken()):
     * "<id>|<sanctum.token_prefix><40 random chars><crc32b of those chars>".
     */
    public static function isWellFormed(?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        $prefix = preg_quote((string) config('sanctum.token_prefix', ''), '/');

        if (! preg_match('/^[1-9]\d{0,18}\|'.$prefix.'([A-Za-z0-9]{40})([0-9a-f]{8})$/', $token, $parts)) {
            return false;
        }

        return hash_equals(hash('crc32b', $parts[1]), $parts[2]);
    }

    private function refuse(string $key): never
    {
        $retryAfter = RateLimiter::availableIn($key);

        throw new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
        ]);
    }
}
