<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The default proxy trust, which is: none.
 *
 * X-Forwarded-For is what every IP-keyed `throttle:` in routes/web.php counts
 * against. If the app trusts that header from any source, a client picks its
 * own rate-limit bucket by picking its own value, and the three-per-minute cap
 * on password reset stops meaning anything. The app used to trust '*' outright;
 * this is the test that stops it drifting back.
 *
 * Deliberately asserts the *default* rather than a configured proxy list: the
 * trust list is read once when the middleware stack is built, so a per-test
 * env change would not reach it. The default is also the branch that matters —
 * a deployment that sets TRUSTED_PROXIES has made a decision, and one that
 * forgets to is the case this protects.
 */
class TrustProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A probe outside the `web` group: TrustProxies is global middleware,
        // so it still runs, while EnsureSiteIsAvailable (which needs the
        // database) does not.
        Route::get('/__trust_probe', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]));
    }

    public function test_a_forged_forwarded_for_header_does_not_change_the_client_ip(): void
    {
        $response = $this->getJson('/__trust_probe', [
            'X-Forwarded-For' => '203.0.113.9',
        ]);

        $response->assertOk();
        $this->assertNotSame(
            '203.0.113.9',
            $response->json('ip'),
            'X-Forwarded-For was honoured from an untrusted source — every IP-keyed throttle is bypassable.'
        );
    }

    public function test_a_forged_forwarded_proto_header_does_not_make_the_request_secure(): void
    {
        $response = $this->getJson('/__trust_probe', [
            'X-Forwarded-Proto' => 'https',
        ]);

        $response->assertOk();
        $this->assertFalse(
            $response->json('secure'),
            'X-Forwarded-Proto was honoured from an untrusted source.'
        );
    }

    public function test_a_forged_forwarded_host_header_does_not_change_the_host(): void
    {
        $response = $this->getJson('/__trust_probe', [
            'X-Forwarded-Host' => 'attacker.example',
        ]);

        $response->assertOk();
        $this->assertNotSame('attacker.example', $response->json('host'));
    }
}
