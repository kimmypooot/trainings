<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->pinTheApplicationUrl();
        $this->definePasswordPolicy();
        $this->defineRateLimiters();
    }

    /**
     * The limits nothing had.
     *
     * Every unauthenticated state-change endpoint in routes/web.php carries a
     * `throttle:` — and the authenticated half of the application carried none
     * at all. A signed-in account could hammer any admin endpoint without
     * limit, and the expensive ones are the exports: each runs a full-table
     * query and streams it, so a loop over the participants register is a
     * denial of service that needs one valid staff login and no cleverness.
     *
     * Two limiters, because the two problems are different sizes.
     */
    private function defineRateLimiters(): void
    {
        /*
         * The blanket limit for signed-in traffic. Deliberately generous — this
         * is a floor against a runaway script or a hammering client, not a
         * usage policy, and a limit low enough to inconvenience a busy officer
         * on a roster screen would be one somebody raises to infinity the first
         * time it fires.
         *
         * Keyed by user id where there is one, so two people behind one office
         * NAT do not share a bucket. Guests fall back to the address — and note
         * that the address is only trustworthy because config/trustedproxy.php
         * now actually works; before that every client behind the proxy shared
         * one key.
         */
        RateLimiter::for('authenticated', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        /*
         * Exports get their own, two orders of magnitude tighter.
         *
         * A register export is the most expensive request the application
         * serves and the one whose cost is invisible from the browser — the
         * page hands it to a plain <a href> and forgets about it. Twenty a
         * minute is far more than the download handshake in useDownload.js will
         * let a person start by clicking, and far less than a loop.
         */
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        /*
         * Sign-in, per address.
         *
         * LoginController already throttles per `email|ip`, which caps attempts
         * against one account — and places no cap at all on one address walking
         * a list of addresses, which is what credential stuffing is. This is
         * that missing half; the two work together, and neither replaces the
         * other.
         */
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }

    /**
     * One statement of what a password has to be.
     *
     * It was stated five times — registration, reset, the header menu's change,
     * and twice in the staff user form — which is four opportunities for the
     * policy to drift, and the kind of drift nobody notices because each site
     * looks right on its own. `Password::defaults()` is Laravel's answer to
     * exactly that, and every one of those five now asks for it.
     *
     * Twelve characters rather than eight. This is a policy decision as much as
     * a technical one and it is the office's to revisit, but for a system
     * holding government personnel records eight is below what an assessor
     * expects to find.
     *
     * `uncompromised()` is the half that does the most work. It checks the
     * candidate against Have I Been Pwned's k-anonymity range API — only the
     * first five characters of the hash leave this server, never the password —
     * and refuses one that appears in a known breach. Length rules stop nobody
     * from choosing `Password1234`; a breach list does.
     *
     * Applied in production only, and not to make the tests convenient: the
     * check is a network call, so under test it would make the suite depend on
     * an external service being reachable, and a password rule that fails when
     * an API is down is a rule that locks people out of their own accounts for
     * reasons unrelated to their password. Locally it would also mean no
     * developer could seed an account without internet.
     */
    private function definePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->numbers();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Build every generated URL from APP_URL, never from the incoming request.
     *
     * Laravel's UrlGenerator takes its root from the request unless told
     * otherwise, which means the Host header decides where a generated link
     * points. For most links that is harmless. For three of them it is not:
     * password reset, email verification and the email-change confirmation are
     * all sent synchronously *inside* a request — deliberately, because they
     * are the mails a person is actively waiting for and the queue is the wrong
     * thing to depend on for those — so their links were built from whatever
     * Host the sender's own request carried.
     *
     * The reset link is the worst of the three, because its token is a broker
     * token rather than a signature over the URL: delivered to an attacker's
     * domain, it still works perfectly against the real one. One request with a
     * forged Host, and the victim's own click completes the takeover.
     *
     * TrustHosts (bootstrap/app.php) refuses such a request outright, and is
     * the first answer. This is the second, and it is the one that holds even
     * where the first is switched off — TrustHosts is inert in `local` and
     * under tests by Laravel's design, and a vhost that forwards an unexpected
     * Host is a deployment fact this repository cannot see. After this line the
     * question "which host did the request claim" simply stops being an input
     * to link generation.
     *
     * Note what is *not* pinned: formatRoot() rewrites the forced root's scheme
     * with the request's own, so http-vs-https still follows the request. That
     * is correct — it is what lets a proxy's X-Forwarded-Proto produce https
     * links once config/trustedproxy.php names the proxy — and it is why this
     * is not also a fix for mixed content.
     *
     * Skipped when APP_URL is empty rather than forcing a root of '', which
     * would break every link in the application to guard against a rarer
     * problem.
     */
    private function pinTheApplicationUrl(): void
    {
        $url = rtrim((string) config('app.url'), '/');

        if ($url === '') {
            return;
        }

        URL::forceRootUrl($url);
    }
}
