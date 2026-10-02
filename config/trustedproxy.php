<?php

/*
|--------------------------------------------------------------------------
| Trusted reverse proxies
|--------------------------------------------------------------------------
|
| X-Forwarded-For / -Host / -Port / -Proto are only honoured when the
| connection comes from one of these addresses. They decide request()->ip()
| (used to key the login rate limits) and whether the request counts as
| HTTPS, so trusting a client that can reach the app directly would let it
| spoof its IP and reset its login allowance.
|
| TRUSTED_PROXIES (read by Illuminate\Http\Middleware\TrustProxies):
|   unset            loopback only (127.0.0.1, ::1). Covers ngrok and an
|                    nginx/Apache reverse proxy on the same machine.
|   comma list       IPs / CIDRs, e.g. "127.0.0.1,::1,10.0.0.0/8" for a load
|                    balancer on a private network.
|   none             trust no proxy (app is reached directly).
|   *                trust whatever address connects. Only safe when the app
|                    is unreachable except through the proxy.
|
*/

$proxies = env('TRUSTED_PROXIES', '127.0.0.1,::1');

return [
    'proxies' => in_array($proxies, [null, '', 'none'], true) ? null : $proxies,
];
