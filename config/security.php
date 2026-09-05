<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Ships in *report-only* mode by default, and that is a deliberate stage
    | rather than a half-measure. A CSP that breaks a page breaks it silently
    | and completely — a blocked module script leaves a blank app — and this
    | application has three things a first policy tends to get wrong: the
    | `@fonts` helper emits an inline <style> block, app.blade.php carries an
    | inline JSON-LD <script>, and Google Analytics (when a measurement id is
    | configured) pulls a script from googletagmanager and beacons to
    | google-analytics. Report-only puts the violations in the browser console
    | and in any report endpoint without taking the site down while they are
    | counted.
    |
    | Set CSP_ENFORCE=true once a deployment has watched the reports and is
    | satisfied the policy is complete. The header switches from
    | Content-Security-Policy-Report-Only to Content-Security-Policy; nothing
    | else changes.
    |
    | Note that clickjacking is *not* waiting on this. X-Frame-Options: DENY is
    | sent enforcing from the start, because it has no failure mode worth
    | staging — nothing in this application is meant to be framed.
    |
    */

    'csp' => [
        'enforce' => (bool) env('CSP_ENFORCE', false),

        // Where the browser posts violations. Optional; without it the reports
        // are console-only, which is enough to tune the policy by hand.
        'report_uri' => env('CSP_REPORT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Only ever sent on a request that actually arrived over HTTPS — a browser
    | ignores HSTS on a plain HTTP response anyway, and sending it there would
    | only be noise. One year, including subdomains.
    |
    | `preload` is deliberately not offered as a flag. Submitting a domain to
    | the preload list is close to irreversible and commits every subdomain to
    | HTTPS forever; that is an office decision made once, not a config toggle.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', true),
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
    ],

];
