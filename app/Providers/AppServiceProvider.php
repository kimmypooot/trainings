<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
