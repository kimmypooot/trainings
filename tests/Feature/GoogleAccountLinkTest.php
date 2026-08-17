<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ImportGoogleAvatar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Connecting Google to an account that already exists — the path for a
 * participant who registered the traditional way and wants one-tap sign-in.
 */
class GoogleAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    /** Mirrors GoogleController::LINK_INTENT, which is private by design. */
    private const INTENT = 'google.link_intent';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-client-id']);

        // The suite runs the queue synchronously, so a connect would otherwise
        // reach out to googleusercontent.com for real. The import has its own
        // test file; here it only matters that it is queued.
        Queue::fake();
    }

    private function participant(array $overrides = []): User
    {
        return User::factory()->create([
            'password' => Hash::make('Password123'),
            'profile_completed_at' => now(),
            'email_verified_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @param  bool|string|null  $verified  The `email_verified` claim exactly as
     *                                      Google would send it — or null to
     *                                      omit it entirely.
     */
    private function fakeGoogleUser(
        string $id,
        string $email,
        ?string $avatar = null,
        bool|string|null $verified = true,
    ): void {
        $googleUser = Mockery::mock(SocialiteUser::class);
        $googleUser->shouldReceive('getId')->andReturn($id);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getAvatar')->andReturn($avatar);
        $googleUser->shouldReceive('getName')->andReturn('Test User');
        $googleUser->shouldReceive('getNickname')->andReturn(null);

        // The raw claims, where the verification flag actually lives.
        $googleUser->user = $verified === null ? [] : ['email_verified' => $verified];

        Socialite::shouldReceive('driver->user')->andReturn($googleUser);
    }

    /** @return array{user_id: int, expires_at: int} */
    private function intentFor(User $user, ?int $expiresAt = null): array
    {
        return [
            'user_id' => $user->getKey(),
            'expires_at' => $expiresAt ?? now()->addMinutes(10)->timestamp,
        ];
    }

    public function test_starting_the_flow_parks_an_intent_and_sends_the_user_to_google(): void
    {
        $user = $this->participant();

        $this->actingAs($user)->get('/profile/google/connect')->assertRedirectContains('accounts.google.com');

        $this->assertSame($user->getKey(), session(self::INTENT)['user_id']);
    }

    public function test_an_already_connected_account_is_not_sent_round_again(): void
    {
        $user = $this->participant(['google_id' => 'google-123']);

        $this->actingAs($user)->get('/profile/google/connect')->assertSessionHas('error');
    }

    public function test_the_callback_connects_google_to_the_signed_in_account(): void
    {
        $user = $this->participant(['email' => 'juan@example.com']);

        $this->fakeGoogleUser('google-123', 'juan@example.com', 'https://lh3.googleusercontent.com/a/photo');

        $this->actingAs($user)
            ->withSession([self::INTENT => $this->intentFor($user)])
            ->get('/auth/google/callback')
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame('google-123', $user->google_id);

        // The photo is copied into TIMS, not linked to Google.
        Queue::assertPushed(
            ImportGoogleAvatar::class,
            fn (ImportGoogleAvatar $job) => $job->userId === $user->getKey()
        );
    }

    /**
     * Imported once, at the moment the identity is attached — never again.
     * That is what lets "remove my photo" stay removed.
     */
    public function test_a_later_sign_in_does_not_re_import_the_photo(): void
    {
        $user = $this->participant([
            'email' => 'juan@example.com',
            'google_id' => 'google-123',
        ]);

        $this->fakeGoogleUser('google-123', 'juan@example.com', 'https://lh3.googleusercontent.com/a/photo');

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
        Queue::assertNotPushed(ImportGoogleAvatar::class);
    }

    /**
     * The sign-up path: a participant who has never used TIMS signs in with
     * Google and gets an account with their Google photo already on it.
     */
    public function test_a_brand_new_google_sign_in_imports_the_photo(): void
    {
        $this->fakeGoogleUser('google-new', 'newcomer@gmail.com', 'https://lh3.googleusercontent.com/a/photo');

        // Not the login screen: a fresh account is active, and reading an unset
        // `is_active` as false once turned every first-time Google sign-up away
        // as deactivated.
        $this->get('/auth/google/callback')->assertRedirect(route('profile.complete'));

        $user = User::where('email', 'newcomer@gmail.com')->firstOrFail();

        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);

        Queue::assertPushed(
            ImportGoogleAvatar::class,
            fn (ImportGoogleAvatar $job) => $job->userId === $user->getKey()
        );
    }

    public function test_a_google_account_with_no_photo_queues_nothing(): void
    {
        $user = $this->participant(['email' => 'juan@example.com']);

        $this->fakeGoogleUser('google-123', 'juan@example.com', avatar: null);

        $this->actingAs($user)
            ->withSession([self::INTENT => $this->intentFor($user)])
            ->get('/auth/google/callback');

        Queue::assertNotPushed(ImportGoogleAvatar::class);
    }

    /**
     * The whole point of the feature: an agency registration plus a personal
     * Gmail. The addresses are not required to match.
     */
    public function test_a_personal_gmail_can_be_connected_to_an_agency_registration(): void
    {
        $user = $this->participant(['email' => 'juan.delacruz@deped.gov.ph']);

        $this->fakeGoogleUser('google-123', 'juan.delacruz@gmail.com');

        $this->actingAs($user)
            ->withSession([self::INTENT => $this->intentFor($user)])
            ->get('/auth/google/callback')
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('juan.delacruz@gmail.com', $user->google_email);
        // Connecting is not an email change: the CSC still writes to the
        // address the account was registered with.
        $this->assertSame('juan.delacruz@deped.gov.ph', $user->email);
    }

    /**
     * A Gmail proves control of the Gmail, not of the agency inbox — so it
     * cannot stand in for the verification email.
     */
    public function test_connecting_a_different_address_does_not_verify_the_tims_email(): void
    {
        $user = $this->participant([
            'email' => 'juan.delacruz@deped.gov.ph',
            'email_verified_at' => null,
        ]);

        $this->fakeGoogleUser('google-123', 'juan.delacruz@gmail.com');

        $this->actingAs($user)
            ->withSession([self::INTENT => $this->intentFor($user)])
            ->get('/auth/google/callback')
            ->assertSessionHas('success');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_a_google_identity_already_on_another_account_is_refused(): void
    {
        $this->participant(['email' => 'first@example.com', 'google_id' => 'google-123']);
        $user = $this->participant(['email' => 'second@example.com']);

        $this->fakeGoogleUser('google-123', 'second@example.com');

        $this->actingAs($user)
            ->withSession([self::INTENT => $this->intentFor($user)])
            ->get('/auth/google/callback')
            ->assertSessionHas('error');

        $this->assertNull($user->refresh()->google_id);
    }

    public function test_an_expired_intent_is_refused(): void
    {
        $user = $this->participant(['email' => 'juan@example.com']);

        $this->fakeGoogleUser('google-123', 'juan@example.com');

        $this->actingAs($user)
            ->withSession([self::INTENT => $this->intentFor($user, now()->subMinute()->timestamp)])
            ->get('/auth/google/callback')
            ->assertSessionHas('error');

        $this->assertNull($user->refresh()->google_id);
    }

    /**
     * The session changed hands between starting the flow and coming back.
     */
    public function test_an_intent_belonging_to_another_user_is_refused(): void
    {
        $starter = $this->participant(['email' => 'juan@example.com']);
        $current = $this->participant(['email' => 'maria@example.com']);

        $this->fakeGoogleUser('google-123', 'maria@example.com');

        $this->actingAs($current)
            ->withSession([self::INTENT => $this->intentFor($starter)])
            ->get('/auth/google/callback')
            ->assertSessionHas('error');

        $this->assertNull($current->refresh()->google_id);
        $this->assertNull($starter->refresh()->google_id);
    }

    /** The matching-address case, where the Google account does prove control. */
    public function test_connecting_the_same_address_verifies_an_unverified_email(): void
    {
        $user = $this->participant(['email' => 'juan@example.com', 'email_verified_at' => null]);

        $this->fakeGoogleUser('google-123', 'juan@example.com');

        $this->actingAs($user)
            ->withSession([self::INTENT => $this->intentFor($user)])
            ->get('/auth/google/callback');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_a_participant_with_a_password_can_disconnect(): void
    {
        $user = $this->participant([
            'google_id' => 'google-123',
            'google_email' => 'juan.delacruz@gmail.com',
        ]);

        $this->actingAs($user)->delete('/profile/google')->assertSessionHas('success');

        $user->refresh();

        $this->assertNull($user->google_id);
        $this->assertNull($user->google_email);
    }

    public function test_disconnecting_is_refused_when_google_is_the_only_way_in(): void
    {
        $user = $this->participant(['password' => null, 'google_id' => 'google-123']);

        $this->actingAs($user)->delete('/profile/google')->assertSessionHas('error');

        $this->assertSame('google-123', $user->refresh()->google_id);
    }

    /**
     * The photo was copied into this system on connect and is the
     * participant's own from then on — disconnecting a sign-in method is no
     * reason to delete it.
     */
    public function test_disconnecting_leaves_the_photo_alone(): void
    {
        $user = $this->participant([
            'google_id' => 'google-123',
            'avatar_path' => 'avatars/mine.jpg',
        ]);

        $this->actingAs($user)->delete('/profile/google');

        $this->assertSame('avatars/mine.jpg', $user->refresh()->avatar_path);
    }

    public function test_an_ordinary_google_sign_in_still_signs_the_user_in(): void
    {
        $user = $this->participant(['email' => 'juan@example.com']);

        $this->fakeGoogleUser('google-123', 'juan@example.com');

        // No intent in session — the sign-in branch, not the connect branch.
        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    /*
     * ── Where a Google sign-in lands ──────────────────────────────────────
     *
     * Some staff authenticate with Google, and the button is the same one, so
     * this branch has to agree with LoginController rather than assume every
     * Google sign-in is a participant.
     */

    public function test_a_staff_google_sign_in_lands_on_the_admin_dashboard(): void
    {
        $staff = $this->participant([
            'email' => 'hrd@csc.gov.ph',
            'role' => Role::Admin,
        ]);

        $this->fakeGoogleUser('google-staff', 'hrd@csc.gov.ph');

        $this->get('/auth/google/callback')->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($staff->fresh());
    }

    public function test_a_google_sign_in_records_the_last_login(): void
    {
        $user = $this->participant(['email' => 'juan@example.com', 'last_login_at' => null]);

        $this->fakeGoogleUser('google-123', 'juan@example.com');

        $this->get('/auth/google/callback');

        $this->assertNotNull($user->refresh()->last_login_at);
    }

    /*
     * ── Recovering a lost state ───────────────────────────────────────────
     *
     * Socialite's state lives in the session, and the session is exactly what
     * goes missing when the callback lands on a different host than the one
     * the flow started on. Left alone that shows the participant the login
     * page and teaches them to click the button twice.
     */

    public function test_a_lost_state_restarts_the_flow_instead_of_showing_the_login_page(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

        $this->get('/auth/google/callback')->assertRedirect(route('auth.google'));

        $this->assertGuest();
    }

    public function test_a_second_lost_state_gives_up_rather_than_looping(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

        // The restart already happened; this is Google sending us back again
        // with a state that still does not match.
        $this->withSession(['google.state_retry' => true])
            ->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('form');

        $this->assertGuest();
    }

    public function test_a_successful_sign_in_spends_the_restart_marker(): void
    {
        $this->participant(['email' => 'juan@example.com']);

        $this->fakeGoogleUser('google-123', 'juan@example.com');

        // A marker left over from an earlier recovery must not cost the next
        // lost state its one restart.
        $this->withSession(['google.state_retry' => true])
            ->get('/auth/google/callback')
            ->assertRedirect(route('dashboard'))
            ->assertSessionMissing('google.state_retry');
    }

    /*
     * ── The email-claim gate ──────────────────────────────────────────────
     *
     * Matching an account by address is only sound when Google confirmed the
     * address. These are the cases that gate exists for.
     */

    public function test_an_unconfirmed_google_email_cannot_take_over_an_existing_account(): void
    {
        $victim = $this->participant(['email' => 'juan@deped.gov.ph']);

        // An attacker's Google account claiming the victim's address, which
        // Google has not confirmed.
        $this->fakeGoogleUser('attacker-google-id', 'juan@deped.gov.ph', verified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();

        $victim->refresh();

        $this->assertNull($victim->google_id);
        $this->assertNotSame('attacker-google-id', $victim->google_id);
    }

    public function test_a_missing_verification_claim_is_treated_as_unconfirmed(): void
    {
        $this->participant(['email' => 'juan@deped.gov.ph']);

        $this->fakeGoogleUser('attacker-google-id', 'juan@deped.gov.ph', verified: null);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_an_unconfirmed_google_email_cannot_create_an_account(): void
    {
        $this->fakeGoogleUser('google-999', 'stranger@example.com', verified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    /**
     * The claim arrives as a string from Google's older userinfo endpoint.
     */
    public function test_a_string_verification_claim_is_understood(): void
    {
        $user = $this->participant(['email' => 'juan@example.com']);

        $this->fakeGoogleUser('google-123', 'juan@example.com', verified: 'true');

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * An identity that was already linked is honoured on `google_id` alone —
     * the linkage was established deliberately and does not depend on what the
     * address claim says today.
     */
    public function test_an_already_linked_identity_signs_in_without_the_claim(): void
    {
        $user = $this->participant([
            'email' => 'juan@deped.gov.ph',
            'google_id' => 'google-123',
        ]);

        $this->fakeGoogleUser('google-123', 'juan.delacruz@gmail.com', verified: null);

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_confirmed_matching_address_still_verifies_on_sign_in(): void
    {
        $user = $this->participant(['email' => 'juan@example.com', 'email_verified_at' => null]);

        $this->fakeGoogleUser('google-123', 'juan@example.com');

        $this->get('/auth/google/callback');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * Signing in with a personal Gmail against an agency registration still
     * owes the office a verification email.
     */
    public function test_signing_in_with_a_linked_gmail_does_not_verify_the_agency_address(): void
    {
        $user = $this->participant([
            'email' => 'juan@deped.gov.ph',
            'email_verified_at' => null,
            'google_id' => 'google-123',
        ]);

        $this->fakeGoogleUser('google-123', 'juan.delacruz@gmail.com');

        $this->get('/auth/google/callback');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_connecting_a_matching_but_unconfirmed_address_does_not_verify_it(): void
    {
        $user = $this->participant(['email' => 'juan@example.com', 'email_verified_at' => null]);

        $this->fakeGoogleUser('google-123', 'juan@example.com', verified: false);

        $this->actingAs($user)
            ->withSession([self::INTENT => $this->intentFor($user)])
            ->get('/auth/google/callback')
            ->assertSessionHas('success');

        $user->refresh();

        // Connected — but the address is still unproven.
        $this->assertSame('google-123', $user->google_id);
        $this->assertNull($user->email_verified_at);
    }
}
