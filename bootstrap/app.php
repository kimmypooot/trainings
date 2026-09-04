<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSiteIsAvailable;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
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
            // First of the three, because a deactivated account should not
            // reach the maintenance gate, the visitor counter or a page
            // payload. `is_active` was checked only at sign-in, which left an
            // existing session — and any "remember me" cookie, which never
            // passes through LoginController at all — working indefinitely
            // after the account was switched off.
            EnsureAccountIsActive::class,
            // The maintenance gate runs before Inertia shares its props, so a
            // site down for maintenance does not count visitors or build a page
            // payload for a user who will only see the 503 page. It is appended
            // (not prepended) because it needs the session middleware to have
            // already run before it reads the signed-in user.
            EnsureSiteIsAvailable::class,
            HandleInertiaRequests::class,
            // Last in, so first out: every response leaving the group passes
            // back through it on the way to the browser, including the ones an
            // exception produced.
            SecurityHeaders::class,
        ]);

        /*
         * Bind each session to the password it was opened with.
         *
         * Changing a password is the other thing people do when they think they
         * have been compromised, and on its own it did nothing about the
         * attacker's session: the password check guards the *change*, not the
         * sessions that already exist. AuthenticateSession stores the password
         * hash in the session and ends any session whose stored hash no longer
         * matches, so a change signs out every other device while leaving the
         * one making the change signed in.
         *
         * It also re-checks the hash carried inside a remember-me cookie, which
         * is a second lock on the same door AccountAccess::rotateRememberToken
         * bolts from the other side.
         *
         * Registered through the framework's own helper rather than appended,
         * so it lands in the priority slot Laravel reserves for it — after the
         * session starts and the user is resolvable, before route bindings.
         *
         * Note what it deliberately skips: an account whose password is null.
         * Those are the Google-only sign-ups, which have no password to bind a
         * session to; they gain this protection the moment they create one.
         */
        $middleware->authenticateSessions();

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
         * The Host header decides nothing about this application.
         *
         * Laravel's URL generator derives its root from the incoming request, so
         * without this an attacker could name the host that a generated link
         * points at simply by sending it. That is not a cosmetic problem: the
         * password-reset link is built in-request (ResetPassword is deliberately
         * not queued, because it is the one mail a person is actively waiting
         * for), so a poisoned Host meant the reset *token* was delivered to
         * whatever domain the attacker asked for, in a genuine email from this
         * office. Clicking it handed over the account.
         *
         * Called with no arguments on purpose: TrustHosts then derives its
         * pattern from config('app.url') and all of its subdomains, which is
         * already the one value every mailed link and the sitemap are built
         * from — so there is nothing here to keep in step with .env, and
         * nothing read through env() at a point where env() does not work (see
         * config/trustedproxy.php for that story). The middleware is inert in
         * `local` and under tests by Laravel's own design, so it costs a
         * developer nothing.
         *
         * This is the outer half of the fix. The inner half is
         * URL::forceRootUrl() in AppServiceProvider, which stops link
         * generation consulting the request at all — belt as well as braces,
         * because this middleware is the half that a misconfigured vhost or a
         * future `local`-ish environment can switch off.
         */
        $middleware->trustHosts();

        /*
         * VS Code port forwarding (and ngrok/Cloudflare Tunnel) terminate TLS at
         * their edge and reach PHP over plain http on loopback, describing the
         * real request in X-Forwarded-*. Without trusting those headers Laravel
         * believes every request is http://localhost, so route() and asset()
         * emit http:// URLs that the browser then blocks as mixed content on an
         * https tunnel — the page loads but its assets and links do not.
         *
         * Only the header bitmask is set here. *Which* proxies to believe is a
         * deployment fact read from config('trustedproxy.proxies'), which
         * TrustProxies falls back to on its own — and it lives there rather
         * than here because this closure runs before .env is loaded, so the
         * env() call that used to sit on this line could never have returned
         * anything. config/trustedproxy.php carries the full account.
         *
         * The bitmask is narrower than Symfony's default: X-Forwarded-Prefix
         * and the AWS ELB header are not trusted, because nothing in this
         * deployment sets them and a header nobody needs is a header nobody
         * should be able to forge.
         */
        $middleware->trustProxies(
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
