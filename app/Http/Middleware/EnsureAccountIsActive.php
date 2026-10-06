<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs a deactivated account out of the website on its next request,
 * whichever screen deactivated it (User Management, Internship Assignment,
 * …). The mobile API has its own check in EnsureMobileAppAccess.
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'This account has been deactivated. Please contact an administrator.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        // Same test as the login form, so an account the login lets in is
        // never logged straight back out.
        if ($user !== null && $user->status === 'inactive') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
        }

        return $next($request);
    }
}
