<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers this application had none of.
 *
 * A grep across app/, config/, bootstrap/ and resources/views/ used to return
 * nothing for X-Frame-Options, Content-Security-Policy, Strict-Transport-
 * Security, X-Content-Type-Options, Referrer-Policy or Permissions-Policy:
 * public/.htaccess was the unmodified Laravel skeleton and AppServiceProvider
 * was empty. Concretely that meant the sign-in page could be framed by any
 * site — a working clickjacking vector against a government portal — and any
 * XSS, if one were ever introduced, would have had nothing containing it.
 *
 * Appended to the `web` group, so it runs last on the way in and therefore
 * first on the way out: every response leaving the group passes back through
 * it, including the ones an exception produced.
 */
class SecurityHeaders
{
    /**
     * Routes whose whole purpose is pointing a camera at a QR code.
     *
     * Permissions-Policy denies the camera everywhere else, which is the point
     * of sending it — but denying it here would break the two scanning doors,
     * and it would break them in the least debuggable way available: the
     * station is offline-first, so an operator would meet a dead viewfinder at
     * a venue with no network to look anything up from.
     *
     * Matched on the route name rather than the path so a future prefix change
     * cannot quietly re-deny it. `station.*` covers the public station's four
     * endpoints; only `show` renders a camera, and the rest cost nothing.
     */
    private const CAMERA_ROUTES = [
        'admin.scanner',
        'station.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            /*
             * Clickjacking, and the one header here that is enforcing from day
             * one. Nothing in this application is meant to be embedded in
             * another page, so DENY has no failure mode to stage — unlike the
             * CSP below, which needs measuring first.
             *
             * Sent alongside frame-ancestors rather than instead of it: the
             * modern directive lives in the CSP, which is report-only for now
             * and therefore not yet enforcing anything.
             */
            'X-Frame-Options' => 'DENY',

            // Stops a browser second-guessing a Content-Type. It matters most
            // on the private-file downloads, where a participant-supplied
            // upload is streamed back and must never be sniffed into HTML.
            'X-Content-Type-Options' => 'nosniff',

            // Full URLs leak: a certificate verification code, a station token
            // and a signed reset link all sit in a path. Same-origin requests
            // keep the full referrer; anything cross-origin gets the origin
            // alone, and an HTTPS→HTTP downgrade gets nothing.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            'Permissions-Policy' => $this->permissionsPolicy($request),

            // Legacy, cheap, and still read by some Adobe clients: no
            // crossdomain.xml here grants anything.
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        $csp = $this->contentSecurityPolicy($request);

        $headers[config('security.csp.enforce')
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only'] = $csp;

        if ($this->shouldSendHsts($request)) {
            $headers['Strict-Transport-Security'] = sprintf(
                'max-age=%d; includeSubDomains',
                (int) config('security.hsts.max_age')
            );
        }

        foreach ($headers as $name => $value) {
            // Never overwrite a header a controller set deliberately. ServeFile
            // sends its own sandboxing CSP with private files, and that one is
            // narrower than this policy — clobbering it would be a downgrade.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * HSTS only on a request that actually arrived over TLS.
     *
     * A browser ignores the header on a plain HTTP response, so this is not a
     * security decision so much as an honesty one — and it keeps a local
     * `artisan serve` from advertising a policy the deployment cannot keep.
     */
    private function shouldSendHsts(Request $request): bool
    {
        return config('security.hsts.enabled') && $request->secure();
    }

    private function permissionsPolicy(Request $request): string
    {
        $camera = in_array($request->route()?->getName(), self::CAMERA_ROUTES, true)
            ? 'camera=(self)'
            : 'camera=()';

        return implode(', ', [
            $camera,
            'microphone=()',
            'geolocation=()',
            'payment=()',
            'usb=()',
            'interest-cohort=()',
        ]);
    }

    /**
     * The policy, assembled from what the application actually loads.
     *
     * Built rather than hard-coded because two of its origins are conditional,
     * and a policy listing origins a deployment does not use is a policy nobody
     * trusts enough to enforce.
     */
    private function contentSecurityPolicy(Request $request): string
    {
        $scripts = ["'self'"];
        $connect = ["'self'"];
        $images = ["'self'", 'data:', 'blob:'];

        /*
         * Google Analytics, only where a measurement id is configured.
         * analytics.js injects the googletagmanager script at runtime and gtag
         * beacons to google-analytics.com, so both origins are needed — and
         * neither belongs in the policy of a deployment that has not turned
         * analytics on.
         */
        if (config('services.ga4.measurement_id')) {
            $scripts[] = 'https://www.googletagmanager.com';
            $connect[] = 'https://www.google-analytics.com';
            $connect[] = 'https://*.google-analytics.com';
            $images[] = 'https://www.google-analytics.com';
        }

        /*
         * The Vite dev server, in local development only.
         *
         * It serves the module graph and the fonts over http://localhost:5173
         * (or the tunnel named in VITE_DEV_ORIGIN) and opens a websocket for
         * hot reload. Without these a developer would meet an unstyled page and
         * a wall of console errors — and would reasonably conclude the policy
         * itself was wrong, rather than that it was merely describing
         * production.
         */
        if (app()->environment('local') && ($origin = $this->viteDevOrigin())) {
            $scripts[] = $origin;
            $connect[] = $origin;
            $connect[] = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $origin);
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $scripts),
            /*
             * 'unsafe-inline' for styles, and it is not laziness.
             *
             * The `@fonts` helper emits an inline <style> block carrying every
             * @font-face rule, and Vue writes inline style attributes for
             * bound widths, chart geometry and transitions throughout the app.
             * Removing it means nonce-ing the font block and rewriting those
             * bindings, which is real work with a real payoff — and it is not
             * this change's work.
             */
            "style-src 'self' 'unsafe-inline'",
            'img-src '.implode(' ', $images),
            "font-src 'self'",
            'connect-src '.implode(' ', $connect),
            // The offline scanning station registers public/scanner-sw.js.
            "worker-src 'self' blob:",
            // Nothing here is meant to be framed, and nothing here frames
            // anything. frame-ancestors is the modern half of X-Frame-Options
            // above; it only bites once the policy is enforced.
            "frame-ancestors 'none'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            // Every form in the app posts to this application. Worth stating
            // because an injected form action is a quiet way to exfiltrate a
            // password field.
            "form-action 'self'",
        ];

        if ($uri = config('security.csp.report_uri')) {
            $directives[] = 'report-uri '.$uri;
        }

        return implode('; ', $directives);
    }

    private function viteDevOrigin(): string
    {
        $configured = trim((string) config('app.vite_dev_origin'));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return 'http://localhost:5173';
    }
}
