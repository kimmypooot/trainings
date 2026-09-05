<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The authenticated half of the application had no limits at all.
 *
 * Every unauthenticated state-change endpoint in routes/web.php carries a
 * `throttle:` and always has. Behind a sign-in there was nothing, and the
 * expensive endpoints are all back there: each export runs a full-table query
 * and streams it, so a loop over the participants register is a denial of
 * service that needs one valid staff login and no cleverness.
 *
 * These assert the limits exist and — as importantly — that they are nowhere
 * near ordinary use. A limit that fires on a busy officer is one somebody
 * raises to infinity the first time it happens.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('authenticated');
        RateLimiter::clear('exports');
        RateLimiter::clear('login');
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user;
    }

    public function test_exports_are_throttled(): void
    {
        $staff = $this->staff();

        // The limit is 20/min; the 21st must be refused.
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($staff)->get('/admin/exports/participants')->assertSuccessful();
        }

        $this->actingAs($staff)->get('/admin/exports/participants')->assertStatus(429);
    }

    /**
     * The point of a limit here is to stop a loop, not a person. Anything a
     * human can do by clicking has to stay comfortably inside it.
     */
    public function test_a_handful_of_exports_is_not_throttled(): void
    {
        $staff = $this->staff();

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($staff)->get('/admin/exports/participants')->assertSuccessful();
        }
    }

    /**
     * Two people in one office share an address, so a limit keyed by address
     * would have one of them lock the other out. Keyed by user id, they do not
     * share a bucket.
     */
    public function test_two_users_do_not_share_an_export_bucket(): void
    {
        $first = $this->staff();
        $second = $this->staff();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($first)->get('/admin/exports/participants')->assertSuccessful();
        }

        $this->actingAs($first)->get('/admin/exports/participants')->assertStatus(429);
        $this->actingAs($second)->get('/admin/exports/participants')->assertSuccessful();
    }

    /**
     * LoginController throttles per email|ip, which caps attempts against one
     * account and places none at all on one address walking a list of
     * addresses. That is credential stuffing, and this is the half that stops
     * it — asserted with a different address each time, so the controller's
     * own limiter cannot be what refuses.
     */
    public function test_sign_in_is_capped_per_address_across_different_accounts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', [
                'email' => "victim{$i}@example.test",
                'password' => 'whatever-they-guessed',
            ]);
        }

        $this->post('/login', [
            'email' => 'victim-final@example.test',
            'password' => 'whatever-they-guessed',
        ])->assertStatus(429);
    }
}
