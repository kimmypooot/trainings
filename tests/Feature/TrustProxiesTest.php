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
 * This file used to test only the default, and said so: "the trust list is read
 * once when the middleware stack is built, so a per-test env change would not
 * reach it". That was an accurate description of a broken design. The list was
 * read by an env() call in bootstrap/app.php, in a closure that runs before
 * .env is parsed — so it was not merely unreachable from a test, it was
 * unreachable from a deployment too. TRUSTED_PROXIES had never done anything,
 * in any environment, and a test that only ever asserted "trust nothing" passed
 * either way and reported the bug as the feature.
 *
 * The list now comes from config/trustedproxy.php, which TrustProxies reads per
 * request. So the configured branch is testable, and is tested here — because
 * the half nobody could exercise is exactly the half that was wrong.
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
            'prefix' => $request->getBaseUrl(),
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

    /**
     * The other half: a proxy that *is* named must actually be believed.
     *
     * Without this the suite cannot tell "trusts nothing because it was told
     * to" from "trusts nothing because the setting is inert", and those two
     * look identical from every assertion above. They were not identical, and
     * the difference cost the app every per-IP throttle behind a load balancer.
     */
    public function test_a_configured_proxy_is_believed(): void
    {
        // The address a test request actually arrives from, so the middleware
        // sees the forged headers as coming *through* a trusted hop.
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $response = $this->getJson('/__trust_probe', [
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
        ]);

        $response->assertOk();
        $this->assertSame(
            '203.0.113.9',
            $response->json('ip'),
            'A named proxy was not believed — TRUSTED_PROXIES is inert again.'
        );
        $this->assertTrue(
            $response->json('secure'),
            'X-Forwarded-Proto from a named proxy did not make the request secure, so asset() will emit http:// behind TLS.'
        );
    }

    /**
     * The link the original bug actually broke: environment to config.
     *
     * The tests above set `config('trustedproxy.proxies')` directly, which
     * proves the middleware reads it per request and nothing more. Deleting
     * config/trustedproxy.php outright leaves every one of them green — which
     * is the same shape of vacuous guard that let the env() bug live in
     * bootstrap/app.php unnoticed. This is the assertion that closes it: the
     * file exists, and TRUSTED_PROXIES arrives through it.
     */
    public function test_the_environment_variable_reaches_the_config(): void
    {
        $path = config_path('trustedproxy.php');

        $this->assertFileExists(
            $path,
            'config/trustedproxy.php is gone, so TRUSTED_PROXIES reaches nothing. '
            .'It must not move back into bootstrap/app.php, where env() cannot work.'
        );

        $original = $_SERVER['TRUSTED_PROXIES'] ?? null;

        try {
            $cases = [
                '' => null,
                '*' => '*',
                '10.0.0.4' => ['10.0.0.4'],
                // Whitespace and a trailing comma are what a hand-edited .env
                // actually looks like.
                ' 10.0.0.4, 10.0.0.5 ,' => ['10.0.0.4', '10.0.0.5'],
            ];

            foreach ($cases as $env => $expected) {
                $_SERVER['TRUSTED_PROXIES'] = $env;

                $this->assertSame(
                    $expected,
                    (require $path)['proxies'],
                    "TRUSTED_PROXIES={$env} did not parse as expected."
                );
            }

            unset($_SERVER['TRUSTED_PROXIES']);

            $this->assertNull(
                (require $path)['proxies'],
                'An unset TRUSTED_PROXIES must fail closed and trust nothing.'
            );
        } finally {
            if ($original === null) {
                unset($_SERVER['TRUSTED_PROXIES']);
            } else {
                $_SERVER['TRUSTED_PROXIES'] = $original;
            }
        }
    }

    /**
     * Reads like a lint rule; is a correctness invariant.
     *
     * The `withMiddleware()` closure in bootstrap/app.php is invoked when the
     * HTTP kernel is resolved, which happens before LoadEnvironmentVariables
     * has parsed .env. Every env() call in that file therefore returns its
     * default, always, in every environment — silently, with no error and no
     * failing test. That is not a rule anyone can be expected to remember at
     * the moment they need it, so it is asserted instead.
     */
    public function test_bootstrap_reads_no_environment_variables(): void
    {
        $tokens = token_get_all(file_get_contents(base_path('bootstrap/app.php')));

        /*
         * Tokenised rather than pattern-matched. The file explains this rule at
         * length, so a naive search finds its own documentation — and stripping
         * comments with a regex is worse still: `'station/*​/unlock'` opens a
         * comment as far as the pattern is concerned, and everything up to the
         * next `*​/` disappears, taking any env() call in between with it. The
         * tokeniser knows a string from a comment from code by construction.
         */
        $calls = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'env') {
                continue;
            }

            // The next significant token being '(' is what makes it a call
            // rather than, say, a parameter named $env or a class named Env.
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $next = $tokens[$j];

                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($next === '(') {
                    $calls[] = 'line '.$token[2];
                }

                break;
            }
        }

        $this->assertSame(
            [],
            $calls,
            'bootstrap/app.php calls env() at '.implode(', ', $calls).'. It always returns its default there, '
            .'because the middleware closure runs before .env is parsed. Move the value into a config file, '
            .'which is evaluated after the environment loads.'
        );
    }

    /**
     * `*` is the tunnelled-development escape hatch, and it has to work or the
     * documentation in config/trustedproxy.php and .env.example is a lie.
     */
    public function test_the_wildcard_escape_hatch_trusts_the_calling_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $response = $this->getJson('/__trust_probe', [
            'X-Forwarded-For' => '203.0.113.9',
        ]);

        $response->assertOk();
        $this->assertSame('203.0.113.9', $response->json('ip'));
    }

    /**
     * The bitmask in bootstrap/app.php is narrower than Symfony's default, and
     * that narrowing is a decision rather than an accident: nothing in this
     * deployment sets X-Forwarded-Prefix, and a header nobody needs is one
     * nobody should be able to forge. It survives a `null` proxy list, so it is
     * asserted through a trusted one.
     */
    public function test_forwarded_prefix_is_not_trusted_even_from_a_named_proxy(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $response = $this->getJson('/__trust_probe', [
            'X-Forwarded-Prefix' => '/hijacked',
        ]);

        $response->assertOk();
        $this->assertSame('', $response->json('prefix'));
    }
}
