<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Malformed UTF-8 input is a 422 on the API, never a 500.
        $middleware->api(prepend: [
            \App\Http\Middleware\RejectMalformedUtf8::class,
        ]);

        // Must stay ahead of auth:sanctum (priority sorting would otherwise
        // move authentication first): it caps 401s per IP before any token
        // lookup.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\ThrottleFailedAuth::class,
        );

        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRoleMiddleware::class,
            'mobile' => \App\Http\Middleware\EnsureMobileAppAccess::class,
        ]);

        // Trust reverse proxies (e.g. ngrok) so Laravel detects the
        // original HTTPS scheme/host from X-Forwarded-* headers instead
        // of generating http:// URLs that browsers block as mixed content.
        // Which proxies are trusted comes from config/trustedproxy.php
        // (TRUSTED_PROXIES, default loopback) — never '*', which would let
        // any client spoof X-Forwarded-For and reset its login rate limit.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isApi = fn (Request $request): bool => $request->is('api', 'api/*');

        // The mobile API never answers with HTML or a redirect: 401
        // (unauthenticated) and 422 (validation) use Laravel's JSON shapes,
        // {message} and {message, errors}.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $isApi($request) || $request->expectsJson()
        );

        // Every other HTTP error on the API is {message}, with a stable
        // message for 404/405 that doesn't leak model class names or routes.
        // ModelNotFoundException / AuthorizationException arrive here already
        // converted to 404 / 403 HTTP exceptions.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            $message = match ($status) {
                404 => 'Not found.',
                405 => 'Method not allowed.',
                default => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : (Response::$statusTexts[$status] ?? 'Error.'),
            };

            return response()->json(['message' => $message], $status, $e->getHeaders());
        });
    })->create();
