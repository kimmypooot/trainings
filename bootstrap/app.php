<?php

use App\Http\Middleware\EnsureSiteIsAvailable;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // The maintenance gate runs before Inertia shares its props, so a
            // site down for maintenance does not count visitors or build a page
            // payload for a user who will only see the 503 page. It is appended
            // (not prepended) because it needs the session middleware to have
            // already run before it reads the signed-in user.
            EnsureSiteIsAvailable::class,
            HandleInertiaRequests::class,
        ]);

        // The public scanning station carries its own credential — an encrypted
        // grant in X-Scan-Grant, checked on every request — and is the one part
        // of the app expected to POST from a page the service worker served
        // hours ago, from a device that has been offline the whole time. Any
        // CSRF token baked into that page is long stale by then, and a session
        // cookie may well have expired, so requiring one would reject exactly
        // the flush that the offline queue exists to protect.
        //
        // Safe because these routes are not cookie-authenticated at all: the
        // grant is what authorises them, and an attacker's forged form cannot
        // read one out of another origin's localStorage.
        $middleware->validateCsrfTokens(except: [
            'station/*/unlock',
            'station/*/sync',
        ]);

        /*
         * The export download handshake.
         *
         * An export is a plain <a href> to a streamed file, so there is no
         * Inertia visit and no XHR — the page gets no event at all, and the
         * button that started a thirty-second register export looked exactly
         * like a button nobody had pressed. People pressed it again, and each
         * press ran the whole query.
         *
         * SpreadsheetExport echoes the caller's `?_dl=` token back in this
         * cookie, which is the only signal a page can see: it lands with the
         * response headers, so the button can stop pretending once the browser
         * has actually taken the download over. useDownload.js reads it and
         * deletes it, which is why it cannot be encrypted — the value has to
         * survive document.cookie unchanged to be matched against the token
         * that was sent. It carries no secret: it is a random string the client
         * itself just generated, being handed straight back.
         */
        $middleware->encryptCookies(except: [
            'dl_token',
        ]);

        /*
         * VS Code port forwarding (and ngrok/Cloudflare Tunnel) terminate TLS at
         * their edge and reach PHP over plain http on loopback, describing the
         * real request in X-Forwarded-*. Without trusting those headers Laravel
         * believes every request is http://localhost, so route() and asset()
         * emit http:// URLs that the browser then blocks as mixed content on an
         * https tunnel — the page loads but its assets and links do not.
         *
         * Which proxies to believe is a property of the deployment rather than
         * of the code, so it comes from TRUSTED_PROXIES, and the default is to
         * trust nothing. X-Forwarded-For is what every IP-keyed throttle in
         * routes/web.php counts against, so a blanket '*' on a reachable host
         * hands an attacker a fresh rate-limit bucket per forged header: the
         * three-per-minute cap on password reset becomes no cap at all. Failing
         * closed costs a tunnelled dev session one .env line; failing open
         * costs the throttles entirely, and costs them silently.
         *
         * '*' is still available for exactly that tunnel case — but it now has
         * to be asked for. TrustProxiesTest is the guard on the default.
         */
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));

        $middleware->trustProxies(
            at: match (true) {
                $trustedProxies === '' => null,
                $trustedProxies === '*' => '*',
                default => array_values(array_filter(
                    array_map(trim(...), explode(',', $trustedProxies)),
                    fn (string $proxy) => $proxy !== '',
                )),
            },
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
