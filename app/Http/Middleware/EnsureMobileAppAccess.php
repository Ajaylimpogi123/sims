<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every authenticated /api/v1 route (runs after auth:sanctum).
 *
 * A token is only usable while its owner is still *active* and has one of
 * the four SIMS roles. Both can change after the token was issued (staff
 * deactivate the account or change its role on the website), so this is
 * re-checked on every request. When the check fails the token that was
 * presented is revoked, so the app is forced back to the login screen.
 */
class EnsureMobileAppAccess
{
    public const CODE_ROLE_NOT_ALLOWED = 'role_not_allowed';

    public const CODE_ACCOUNT_INACTIVE = 'account_inactive';

    public const MESSAGE_ROLE_NOT_ALLOWED = 'This account does not have access to the mobile app.';

    public const MESSAGE_ACCOUNT_INACTIVE = 'This account has been deactivated. Please contact an administrator.';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // auth:sanctum runs first; this is only a safety net.
        if ($user === null) {
            throw new AuthenticationException;
        }

        $code = match (true) {
            ! $user->canUseMobileApp() => self::CODE_ROLE_NOT_ALLOWED,
            ! $user->isActive() => self::CODE_ACCOUNT_INACTIVE,
            default => null,
        };

        if ($code === null) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return self::deny($code);
    }

    public static function deny(string $code): Response
    {
        return response()->json([
            'message' => $code === self::CODE_ROLE_NOT_ALLOWED
                ? self::MESSAGE_ROLE_NOT_ALLOWED
                : self::MESSAGE_ACCOUNT_INACTIVE,
            'code' => $code,
        ], 403);
    }
}
