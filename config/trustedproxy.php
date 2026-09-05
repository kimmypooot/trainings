<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Which proxies may describe the real request in X-Forwarded-*. Empty means
| trust none, which is the safe default: X-Forwarded-For is what every IP-keyed
| `throttle:` in routes/web.php counts against, so trusting it from anywhere
| lets a client pick its own rate-limit bucket by picking its own header.
|
| This lives in a config file rather than in bootstrap/app.php, and the reason
| is worth stating because the obvious placement is the broken one. The
| `withMiddleware()` closure in bootstrap/app.php runs when the HTTP kernel is
| *resolved*, which is before Laravel's LoadEnvironmentVariables bootstrapper
| has parsed .env at all. An `env()` call there therefore always returns its
| default — not merely under `config:cache`, but in every environment, always.
| TRUSTED_PROXIES was read that way and was silently inert for the life of the
| setting: setting it did nothing, and neither did the documented `*` escape
| hatch for tunnelled development.
|
| Config files are the one place `env()` is safe, because they are evaluated
| after the environment is loaded and their result is what `config:cache`
| freezes. Illuminate\Http\Middleware\TrustProxies already falls back to
| `config('trustedproxy.proxies')` when no static value was set, so naming the
| file this way is all the wiring required — bootstrap/app.php now sets only
| the header bitmask, which is a literal and was never affected.
|
| Set it to `*` when running behind a VS Code / ngrok / Cloudflare tunnel on a
| machine nothing else can reach — without it the tunnel's https is invisible
| to Laravel and asset() emits http:// URLs the browser blocks as mixed
| content. In production name the actual proxy addresses instead, comma
| separated: TRUSTED_PROXIES=10.0.0.4,10.0.0.5
|
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [

    /*
     * null  — trust nothing (the default, and the one that fails closed)
     * '*'   — trust whatever proxy the request arrived through
     * array — trust exactly these addresses
     */
    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' => '*',
        default => array_values(array_filter(
            array_map(trim(...), explode(',', $proxies)),
            fn (string $proxy) => $proxy !== '',
        )),
    },

];
