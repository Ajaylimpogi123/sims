<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  mixed  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! auth()->check()) {
            return redirect('/login');
        }

        // Convert role parameters to integers
        $allowedRoles = array_map('intval', $roles);

        if (in_array(auth()->user()->role_id, $allowedRoles, true)) {
            return $next($request);
        }

        abort(403, 'Unauthorized access');
    }
}
