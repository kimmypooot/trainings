<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Profile;
use App\Models\User;
use App\Support\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Taking access away actually takes it away.
 *
 * Three credentials outlive a decision about an account, and before C3 all
 * three survived every attempt to end them:
 *
 *  - the *session*: `is_active` was read in LoginController and the Google
 *    callback and nowhere else, so deactivating an account left whatever
 *    session it already held working — and a session is refreshed on every
 *    request, so "already held" meant indefinitely;
 *  - the *remember-me cookie*: honoured inside SessionGuard::user(), which
 *    never reaches LoginController, so it signed a deactivated account back in;
 *  - and it survived a password change and a password reset too, because both
 *    write with a forceFill naming only `password`, dropping the
 *    `remember_token` rotation Laravel's own starter kits perform.
 *
 * The compounding case is the one that made this critical: an attacker holding
 * one remember-me cookie had 400 days of access that neither a password reset,
 * nor a password change, nor deactivating the account would end.
 */
class AccountRevocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A participant who can actually reach /dashboard — the profile and
     * verification gates sit in front of it, and a user who fails those would
     * redirect for reasons that have nothing to do with what is under test.
     */
    private function participant(array $attributes = []): User
    {
        $user = User::factory()->create([
            'password' => 'password',
            'is_active' => true,
            'profile_completed_at' => now(),
            ...$attributes,
        ]);

        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => Role::SuperAdmin,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    // ---------------------------------------------------------------- session

    public function test_a_deactivated_account_is_ejected_from_a_session_it_already_holds(): void
    {
        $user = $this->participant();

        $this->actingAs($user)->get('/dashboard')->assertSuccessful();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['form' => EnsureAccountIsActive::MESSAGE]);

        $this->assertGuest();
    }

    /**
     * Staff are the case the office actually reaches for this button for, and
     * they live behind a different set of gates, so they get their own pass.
     */
    public function test_a_deactivated_staff_account_is_ejected_from_the_admin_area(): void
    {
        $staff = $this->participant(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->actingAs($staff)->get('/admin')->assertSuccessful();

        $staff->forceFill(['is_active' => false])->save();

        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * The ejection must not become a redirect loop: /login is where we send
     * them, so it has to remain reachable.
     */
    public function test_the_login_page_stays_reachable_after_ejection(): void
    {
        $user = $this->participant();

        $this->actingAs($user);
        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/login')->assertSuccessful();
    }

    // ----------------------------------------------------------- remember me

    /**
     * The compounding failure, end to end.
     *
     * Sign in with "remember me", keep only the recaller cookie, deactivate the
     * account, then come back as a browser whose session has expired. Laravel
     * resolves that request through the recaller, entirely inside SessionGuard,
     * without passing LoginController — which is why the sign-in check was
     * never the whole answer.
     */
    public function test_a_remember_me_cookie_cannot_resurrect_a_deactivated_account(): void
    {
        $user = $this->participant(['email' => 'remembered@example.test']);

        $response = $this->post('/login', [
            'email' => 'remembered@example.test',
            'password' => 'password',
            'remember' => true,
        ]);

        $recaller = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_'));

        $this->assertNotNull($recaller, 'Sign-in did not issue a remember-me cookie, so this test proves nothing.');

        $asRememberedBrowser = function () use ($recaller) {
            /*
             * A new browser: no session, only the cookie the last one kept.
             *
             * Deliberately NOT Auth::logout(). SessionGuard::logout() cycles
             * `remember_token` on the way out — which is correct behaviour and
             * is precisely what this test must not do, because it would destroy
             * the credential under test and leave the assertion below passing
             * against a cookie that had already been revoked by the test
             * itself. forgetGuards() drops the resolved user without touching
             * the record, which is what "the session expired" actually looks
             * like.
             */
            $this->flushSession();
            $this->app['auth']->forgetGuards();

            /*
             * withUnencryptedCookie, not withCookie. The name is misleading in
             * this context: withCookie() *encrypts the value for you*, and the
             * value captured off the response has already been encrypted on its
             * way out — so it would be encrypted twice, arrive undecryptable,
             * and be discarded as absent. The request would then simply be a
             * guest's, redirect to /login, and satisfy the assertion below
             * without ever presenting the credential.
             *
             * This method sends the bytes verbatim, which is exactly what a
             * browser does with a Set-Cookie it was handed.
             */
            return $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
                ->get('/dashboard');
        };

        /*
         * Control, and it is not optional.
         *
         * Without it this test passes for the wrong reason: if the recaller is
         * never honoured — a cookie the test client mangles, a session the
         * framework declines to rebuild — then the request is simply a guest's,
         * it redirects to /login, and the assertion below is satisfied by an
         * attack that was never attempted. Proving the cookie *does* sign this
         * account in first is what makes the refusal afterwards mean something.
         */
        $asRememberedBrowser()->assertSuccessful();

        // assertAuthenticatedAs() takes a *guard* as its second argument, not a
        // message, so the explanation goes on its own assertion.
        $this->assertTrue(
            Auth::check(),
            'The remember-me cookie did not sign the account back in, so the refusal below would prove nothing.'
        );
        $this->assertTrue(Auth::viaRemember(), 'Authenticated, but not through the remember-me cookie.');
        $this->assertSame($user->getKey(), Auth::id());

        $user->forceFill(['is_active' => false])->save();

        $asRememberedBrowser()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_deactivation_rotates_the_remember_token(): void
    {
        $user = $this->participant(['remember_token' => Str::random(60)]);
        $before = $user->remember_token;

        $this->actingAs($this->superAdmin())
            ->post("/admin/participants/{$user->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertNotSame($before, $user->fresh()->remember_token);
    }

    public function test_deactivating_a_staff_account_rotates_its_remember_token(): void
    {
        $staff = $this->participant(['role' => Role::Admin, 'remember_token' => Str::random(60)]);
        $before = $staff->remember_token;

        $this->actingAs($this->superAdmin())
            ->post("/admin/users/{$staff->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($staff->fresh()->is_active);
        $this->assertNotSame($before, $staff->fresh()->remember_token);
    }

    /**
     * The edit form is a second way to reach the same decision, and an account
     * switched off there has to lose its access just as decisively as one
     * switched off with the toggle. Two doors to one outcome is exactly where a
     * guard gets applied to only one of them.
     */
    public function test_deactivating_through_the_edit_form_revokes_access(): void
    {
        $staff = $this->participant([
            'role' => Role::Admin,
            'name' => 'Reyna Ocampo',
            'email' => 'reyna@csc.gov.ph',
            'remember_token' => Str::random(60),
        ]);
        $before = $staff->remember_token;

        $this->actingAs($this->superAdmin())
            ->put("/admin/users/{$staff->id}", [
                'name' => 'Reyna Ocampo',
                'email' => 'reyna@csc.gov.ph',
                'role' => Role::Admin->value,
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $staff->refresh();

        $this->assertFalse($staff->is_active);
        $this->assertNotSame($before, $staff->remember_token);
    }

    /**
     * The same form left active must not revoke — editing somebody's name is
     * not a security event, and signing them out for it would be a surprise.
     */
    public function test_editing_an_active_account_does_not_revoke_it(): void
    {
        $staff = $this->participant([
            'role' => Role::Admin,
            'email' => 'still.active@csc.gov.ph',
            'remember_token' => Str::random(60),
        ]);
        $before = $staff->remember_token;

        $this->actingAs($this->superAdmin())
            ->put("/admin/users/{$staff->id}", [
                'name' => 'Renamed Person',
                'email' => 'still.active@csc.gov.ph',
                'role' => Role::Admin->value,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $staff->refresh();

        $this->assertTrue($staff->is_active);
        $this->assertSame($before, $staff->remember_token);
    }

    /**
     * Reactivating must not revoke — the point of the toggle's other direction
     * is to give the account back, not to punish it further.
     */
    public function test_reactivation_leaves_the_remember_token_alone(): void
    {
        $user = $this->participant(['is_active' => false, 'remember_token' => Str::random(60)]);
        $before = $user->remember_token;

        $this->actingAs($this->superAdmin())
            ->post("/admin/participants/{$user->id}/toggle")
            ->assertRedirect();

        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame($before, $user->fresh()->remember_token);
    }

    // -------------------------------------------------------------- passwords

    public function test_a_password_reset_rotates_the_remember_token(): void
    {
        Notification::fake();

        $user = $this->participant(['email' => 'reset@example.test', 'remember_token' => Str::random(60)]);
        $before = $user->remember_token;

        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'reset@example.test',
            'password' => 'BrandNewPass123',
            'password_confirmation' => 'BrandNewPass123',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertTrue(Hash::check('BrandNewPass123', $user->password));
        $this->assertNotSame(
            $before,
            $user->remember_token,
            'A reset is what somebody does when they think they are compromised — the stolen recaller must die with it.'
        );
    }

    public function test_a_password_change_rotates_the_remember_token(): void
    {
        $user = $this->participant(['remember_token' => Str::random(60)]);
        $before = $user->remember_token;

        $this->actingAs($user)->post('/change-password', [
            'current_password' => 'password',
            'password' => 'RotatedPass123',
            'password_confirmation' => 'RotatedPass123',
        ])->assertSessionHas('success');

        $this->assertNotSame($before, $user->fresh()->remember_token);
    }

    /**
     * AuthenticateSession's half: a session opened against one password stops
     * working once the password changes, while the device that made the change
     * stays signed in.
     */
    public function test_changing_the_password_keeps_this_device_signed_in(): void
    {
        $user = $this->participant();

        $this->actingAs($user)->get('/dashboard')->assertSuccessful();

        $this->post('/change-password', [
            'current_password' => 'password',
            'password' => 'RotatedPass123',
            'password_confirmation' => 'RotatedPass123',
        ])->assertSessionHas('success');

        $this->get('/dashboard')->assertSuccessful();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_session_opened_against_an_old_password_is_ended(): void
    {
        $user = $this->participant();

        // A device that signed in and had its password hash bound to the session.
        $this->actingAs($user)->get('/dashboard')->assertSuccessful();

        // Somewhere else — another device, or an administrator — the password moves.
        $user->forceFill(['password' => 'ChangedElsewhere123'])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * Google-only accounts have no password to bind a session to, so
     * AuthenticateSession skips them by design. Asserted so that the skip stays
     * a deliberate exemption rather than becoming a silent lockout if the
     * framework's guard clause ever changes.
     */
    public function test_an_account_without_a_password_can_still_use_the_app(): void
    {
        $user = $this->participant(['password' => null, 'google_id' => 'google-abc']);

        $this->actingAs($user)->get('/dashboard')->assertSuccessful();
        $this->get('/dashboard')->assertSuccessful();
        $this->assertAuthenticatedAs($user);
    }

    // --------------------------------------------------------- session sweep

    /**
     * The database driver is what this application runs; the suite runs on
     * `array`, so the driver is named explicitly here rather than assumed.
     */
    public function test_revoking_deletes_the_accounts_session_rows(): void
    {
        config(['session.driver' => 'database']);

        $user = $this->participant();
        $other = $this->participant();

        foreach ([$user, $other] as $owner) {
            DB::table('sessions')->insert([
                'id' => Str::random(40),
                'user_id' => $owner->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => base64_encode('x'),
                'last_activity' => time(),
            ]);
        }

        AccountAccess::revoke($user);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
        $this->assertSame(
            1,
            DB::table('sessions')->where('user_id', $other->getKey())->count(),
            'Revoking one account cleared another account’s sessions.'
        );
    }

    /**
     * Under any other driver there is no table to sweep, and a revocation must
     * not error — the deactivation itself is the point of the request.
     */
    public function test_revoking_is_safe_when_sessions_are_not_in_the_database(): void
    {
        config(['session.driver' => 'array']);

        $user = $this->participant(['remember_token' => Str::random(60)]);
        $before = $user->remember_token;

        AccountAccess::revoke($user);

        $this->assertNotSame($before, $user->fresh()->remember_token);
    }
}
