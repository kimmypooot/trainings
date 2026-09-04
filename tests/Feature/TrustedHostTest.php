<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Host header must not decide where a generated link points.
 *
 * Laravel's UrlGenerator takes its root from the incoming request, and three of
 * this application's emails are built *inside* a request rather than on the
 * queue — password reset, email verification, and the email-change
 * confirmation — because each is a mail somebody is actively waiting for and
 * the queue is the wrong dependency for those. So the Host header on the
 * sender's own request decided the hostname in the link.
 *
 * The reset link is the dangerous one. Its token is a broker token, not a
 * signature over the URL, so a copy delivered to an attacker's domain still
 * works perfectly against the real one: one request with a forged Host, one
 * genuine-looking email from this office, one click by the victim, and the
 * account is gone. No password and no prior access required.
 *
 * Two mechanisms now stop that, and both are asserted here:
 *
 *  - TrustHosts (bootstrap/app.php) refuses an untrusted Host outright. It is
 *    inert in `local` and under tests by Laravel's own design, so what can be
 *    asserted about it is that it is registered and that its pattern is derived
 *    from APP_URL — which is the part that could silently regress.
 *  - URL::forceRootUrl (AppServiceProvider) removes the request from link
 *    generation entirely. That one *is* live under test, so the takeover itself
 *    is reproduced below and shown not to work.
 */
class TrustedHostTest extends TestCase
{
    use RefreshDatabase;

    private const POISONED = 'http://evil.example.test';

    /**
     * The finding, reproduced: request a reset with a forged Host and read the
     * link that would have been emailed.
     */
    public function test_a_forged_host_cannot_move_the_password_reset_link(): void
    {
        Notification::fake();

        $victim = User::factory()->create(['email' => 'victim@example.test']);

        // An absolute URL is passed through by the URL generator untouched, so
        // this genuinely arrives with evil.example.test as its Host — a header
        // handed to $this->post() would be overwritten by Request::create().
        $this->post(self::POISONED.'/forgot-password', ['email' => 'victim@example.test'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($victim, ResetPassword::class, function (ResetPassword $notification) use ($victim) {
            $url = $notification->toMail($victim)->actionUrl;

            $this->assertStringStartsWith(
                rtrim(config('app.url'), '/'),
                $url,
                'The reset link was built from the request Host — the token is deliverable to an attacker.'
            );
            $this->assertStringNotContainsString('evil.example.test', $url);

            return true;
        });
    }

    /**
     * The verification link is signed over its own absolute URL, so a poisoned
     * copy would not validate here — but it still leaks the user id and hash
     * and still arrives from this office's mail server, which is a phishing
     * lure with CSC's name on it. Pinned for the same reason.
     */
    public function test_a_forged_host_cannot_move_the_email_verification_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(self::POISONED.'/email/verification-notification')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringStartsWith(rtrim(config('app.url'), '/'), $url);
            $this->assertStringNotContainsString('evil.example.test', $url);

            return true;
        });
    }

    /**
     * Plain link generation, with no mail involved — the property the two tests
     * above depend on, stated on its own so a failure is legible.
     */
    public function test_route_urls_ignore_the_request_host(): void
    {
        $this->get(self::POISONED.'/login')->assertOk();

        $this->assertStringStartsWith(rtrim(config('app.url'), '/'), route('password.request'));
        $this->assertStringStartsWith(rtrim(config('app.url'), '/'), url('/'));
    }

    /**
     * The outer half. TrustHosts does not run under test, so this asserts the
     * two things that would break it in production: that it is in the global
     * stack at all, and that the pattern it would apply is APP_URL's host.
     */
    public function test_trust_hosts_is_registered_and_derives_its_pattern_from_the_app_url(): void
    {
        $global = app(Kernel::class)->getGlobalMiddleware();

        $this->assertContains(
            TrustHosts::class,
            $global,
            'TrustHosts is not in the global middleware stack, so an untrusted Host is accepted.'
        );

        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $patterns = (new TrustHosts(app()))->hosts();

        $this->assertNotEmpty(array_filter(
            $patterns,
            fn (?string $pattern) => $pattern !== null && preg_match('#'.$pattern.'#i', (string) $host) === 1
        ), 'No trusted-host pattern matches APP_URL, so the application would refuse its own hostname.');

        $this->assertEmpty(array_filter(
            $patterns,
            fn (?string $pattern) => $pattern !== null && preg_match('#'.$pattern.'#i', 'evil.example.test') === 1
        ), 'An arbitrary host matches the trusted-host pattern.');
    }
}
