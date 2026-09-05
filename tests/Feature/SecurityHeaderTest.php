<?php

namespace Tests\Feature;

use App\Models\ScanLink;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The headers every response has to carry.
 *
 * There were none. A grep across app/, config/, bootstrap/ and
 * resources/views/ returned nothing for X-Frame-Options, CSP, HSTS,
 * X-Content-Type-Options, Referrer-Policy or Permissions-Policy: public/.htaccess
 * was the unmodified Laravel skeleton and AppServiceProvider was empty. The
 * sign-in page of a government portal could be framed by any site.
 *
 * Asserted on a real response rather than by reading the middleware, because
 * the failure that matters is a header that never reaches the browser — a
 * middleware registered in the wrong group, or one whose response object is
 * replaced further down the stack, looks perfectly correct in the source.
 */
class SecurityHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_refuses_to_be_framed(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_the_basic_hardening_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    /**
     * Report-only until a deployment has watched the reports. Asserted so that
     * flipping CSP_ENFORCE is a decision somebody makes, not something that
     * happens by accident — an enforcing policy that is wrong takes the site
     * down silently.
     */
    public function test_the_content_security_policy_ships_report_only(): void
    {
        $response = $this->get('/login');

        $response->assertHeaderMissing('Content-Security-Policy');

        $policy = $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotNull($policy);
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
    }

    public function test_enforcing_the_policy_switches_the_header(): void
    {
        config(['security.csp.enforce' => true]);

        $response = $this->get('/login');

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * The policy must name only what the deployment actually loads. A policy
     * listing origins nobody uses is one nobody trusts enough to enforce.
     */
    public function test_analytics_origins_appear_only_when_analytics_is_configured(): void
    {
        config(['services.ga4.measurement_id' => null]);
        $this->assertStringNotContainsString(
            'googletagmanager',
            (string) $this->get('/login')->headers->get('Content-Security-Policy-Report-Only')
        );

        config(['services.ga4.measurement_id' => 'G-TEST123']);
        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('https://www.googletagmanager.com', $policy);
        $this->assertStringContainsString('https://www.google-analytics.com', $policy);
    }

    // ------------------------------------------------------------ the camera

    /*
     * Permissions-Policy denies the camera, which is the point of sending it —
     * and is also the one way this change could break a venue. The scanning
     * doors are offline-first, so an operator meeting a dead viewfinder would
     * have no network to look anything up from. Both doors are asserted open,
     * and one ordinary page asserted closed, so neither half can drift.
     */

    public function test_the_camera_is_denied_on_an_ordinary_page(): void
    {
        $this->assertStringContainsString(
            'camera=()',
            (string) $this->get('/login')->headers->get('Permissions-Policy')
        );
    }

    public function test_the_staff_scanner_may_use_the_camera(): void
    {
        $staff = User::factory()->create(['role' => 'admin', 'profile_completed_at' => now()]);

        $this->assertStringContainsString(
            'camera=(self)',
            (string) $this->actingAs($staff)->get('/admin/scanner')->headers->get('Permissions-Policy')
        );
    }

    public function test_the_public_station_may_use_the_camera(): void
    {
        $issuer = User::factory()->create(['role' => 'admin', 'profile_completed_at' => now()]);
        [$link] = ScanLink::issue(Training::factory()->create(), $issuer);

        $this->assertStringContainsString(
            'camera=(self)',
            (string) $this->get("/station/{$link->token}")->headers->get('Permissions-Policy')
        );
    }

    // -------------------------------------------------------------- HSTS

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->get('https://localhost:8000/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_can_be_switched_off_for_a_deployment_without_tls(): void
    {
        config(['security.hsts.enabled' => false]);

        $this->get('https://localhost:8000/login')->assertHeaderMissing('Strict-Transport-Security');
    }
}
