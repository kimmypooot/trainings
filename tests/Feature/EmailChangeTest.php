<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use App\Support\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Moving an account to a different address.
 *
 * The reason the feature exists: a participant who transfers out of the agency
 * they registered with loses the inbox their account lives at, and their only
 * self-service route back used to be a second registration. So the cases that
 * matter most here are the ones where the old address is already unreachable —
 * it must not be needed to make the change — and the ones where somebody else
 * is driving, where the old address is the only alarm that still works.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function participant(array $overrides = []): User
    {
        $user = User::factory()->create([
            'email' => 'juan@deped.gov.ph',
            'password' => Hash::make('Password123'),
            'profile_completed_at' => now(),
            'email_verified_at' => now(),
            ...$overrides,
        ]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    /** The signed link exactly as ConfirmEmailChange builds it. */
    private function confirmationUrl(User $user, string $email): string
    {
        return URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(EmailChangeService::LINK_TTL_MINUTES),
            ['id' => $user->getKey(), 'hash' => sha1($email)]
        );
    }

    private function request(User $user, string $email, string $password = 'Password123'): void
    {
        $this->actingAs($user)
            ->post('/profile/email', ['email' => $email, 'current_password' => $password])
            ->assertSessionHas('success');
    }

    public function test_requesting_a_change_does_not_change_anything_yet(): void
    {
        Notification::fake();

        $user = $this->participant();

        $this->request($user, 'juan@lgu.gov.ph');

        $user->refresh();

        // The account is still where it was. This is the whole safety property:
        // a typo must not be able to move an account somewhere unreachable.
        $this->assertSame('juan@deped.gov.ph', $user->email);
        $this->assertSame('juan@lgu.gov.ph', $user->pending_email);
    }

    public function test_the_link_goes_to_the_new_address_and_the_warning_to_the_old(): void
    {
        Notification::fake();

        $user = $this->participant();

        $this->request($user, 'juan@lgu.gov.ph');

        // The confirmation is addressed on demand — sent through the account it
        // would land in the inbox being left, and prove nothing.
        Notification::assertSentOnDemand(
            ConfirmEmailChange::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'juan@lgu.gov.ph'
        );

        // And the alarm goes to the address a hijacked session cannot read.
        Notification::assertSentTo($user, EmailChangeRequested::class);
    }

    public function test_opening_the_link_completes_the_change(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');

        $this->actingAs($user)
            ->get($this->confirmationUrl($user, 'juan@lgu.gov.ph'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame('juan@lgu.gov.ph', $user->email);
        $this->assertNull($user->pending_email);
        // Opening the link is the proof a verification email exists to collect,
        // so the participant is not asked for it twice.
        $this->assertNotNull($user->email_verified_at);
    }

    /**
     * The common case: the link is opened in whatever browser has the new
     * mailbox open, which is not the one holding the session.
     */
    public function test_the_link_works_for_a_signed_out_visitor(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');

        $this->post('/logout');

        $this->get($this->confirmationUrl($user, 'juan@lgu.gov.ph'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertSame('juan@lgu.gov.ph', $user->refresh()->email);

        // Confirming an address is not a way to sign in as its owner.
        $this->assertGuest();
    }

    public function test_the_new_address_can_then_be_signed_in_with(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');
        $this->actingAs($user)->get($this->confirmationUrl($user, 'juan@lgu.gov.ph'));
        $this->post('/logout');

        $this->post('/login', ['email' => 'juan@lgu.gov.ph', 'password' => 'Password123']);

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_an_address_belonging_to_someone_else_is_refused(): void
    {
        Notification::fake();

        $this->participant(['email' => 'taken@lgu.gov.ph']);
        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/email', [
                'email' => 'taken@lgu.gov.ph',
                'current_password' => 'Password123',
            ])
            ->assertSessionHasErrors('email');

        $this->assertNull($user->refresh()->pending_email);
        Notification::assertNothingSent();
    }

    /**
     * A pending claim must not take an address out of circulation — otherwise
     * typing somebody else's address here is enough to stop them using it.
     */
    public function test_a_pending_claim_does_not_reserve_the_address(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'shared@lgu.gov.ph');

        $other = $this->participant(['email' => 'maria@deped.gov.ph']);

        /*
         * A different person, so a different browser.
         *
         * AuthenticateSession binds a session to the password it was opened
         * with, and the request above left this test's session holding the
         * *first* participant's password hash. Acting as somebody else without
         * clearing it presents that session under a second identity, which the
         * middleware correctly reads as a stolen session and ends — the POST
         * below would then redirect to /login and the missing `success` key
         * would look like a bug in the email-change flow.
         *
         * Nothing to reproduce in production, where two people never share a
         * session and signing in regenerates it. It is `actingAs` that is the
         * shortcut: it swaps the user without the sign-in that would normally
         * come with it.
         */
        $this->flushSession();

        $this->actingAs($other)
            ->post('/profile/email', [
                'email' => 'shared@lgu.gov.ph',
                'current_password' => 'Password123',
            ])
            ->assertSessionHas('success');

        $this->assertSame('shared@lgu.gov.ph', $other->refresh()->pending_email);
    }

    /** Whoever confirms first gets it; the loser is told, not crashed into. */
    public function test_an_address_claimed_before_the_link_is_opened_is_refused(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'shared@lgu.gov.ph');

        // Somebody else registers it in the meantime.
        $this->participant(['email' => 'shared@lgu.gov.ph']);

        $this->actingAs($user)
            ->get($this->confirmationUrl($user, 'shared@lgu.gov.ph'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error');

        $this->assertSame('juan@deped.gov.ph', $user->refresh()->email);
    }

    /**
     * The same collision, met by somebody who is signed out — the case the
     * error bag could not have reached, since an emailed link's "back" is a
     * mail client.
     */
    public function test_a_signed_out_visitor_is_told_when_the_address_was_claimed(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'shared@lgu.gov.ph');
        $url = $this->confirmationUrl($user, 'shared@lgu.gov.ph');

        $this->participant(['email' => 'shared@lgu.gov.ph']);
        $this->post('/logout');

        $this->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertSame('juan@deped.gov.ph', $user->refresh()->email);
    }

    public function test_the_current_password_is_required(): void
    {
        Notification::fake();

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/email', ['email' => 'juan@lgu.gov.ph', 'current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');

        $this->assertNull($user->refresh()->pending_email);
        Notification::assertNothingSent();
    }

    /**
     * The participants most likely to need this are the ones who signed up with
     * a personal Gmail — demanding a password they were never issued would make
     * the feature a dead end for exactly them.
     */
    public function test_a_google_only_account_needs_no_password(): void
    {
        Notification::fake();

        $user = $this->participant(['password' => null, 'google_id' => 'google-123']);

        $this->actingAs($user)
            ->post('/profile/email', ['email' => 'juan@lgu.gov.ph'])
            ->assertSessionHas('success');

        $this->assertSame('juan@lgu.gov.ph', $user->refresh()->pending_email);
    }

    public function test_a_pending_change_can_be_cancelled(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');

        $this->actingAs($user)->delete('/profile/email')->assertSessionHas('success');

        $this->assertNull($user->refresh()->pending_email);
    }

    /** Cancelling is what the security notice tells the victim to do, so it has to bite. */
    public function test_cancelling_kills_the_link_already_sent(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');

        $url = $this->confirmationUrl($user, 'juan@lgu.gov.ph');

        $this->actingAs($user)->delete('/profile/email');

        $this->actingAs($user)->get($url)->assertSessionHas('error');

        $this->assertSame('juan@deped.gov.ph', $user->refresh()->email);
    }

    /** A second request supersedes the first, links included. */
    public function test_a_replaced_request_invalidates_the_earlier_link(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'first@lgu.gov.ph');
        $firstUrl = $this->confirmationUrl($user, 'first@lgu.gov.ph');

        $this->request($user, 'second@lgu.gov.ph');

        $this->actingAs($user)->get($firstUrl)->assertSessionHas('error');

        $this->assertSame('juan@deped.gov.ph', $user->refresh()->email);
    }

    public function test_an_expired_request_cannot_be_confirmed(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');

        $this->travel(EmailChangeService::LINK_TTL_MINUTES + 1)->minutes();

        $this->actingAs($user)
            ->get($this->confirmationUrl($user, 'juan@lgu.gov.ph'))
            ->assertSessionHas('error');

        $this->assertSame('juan@deped.gov.ph', $user->refresh()->email);
    }

    public function test_an_unsigned_link_is_rejected(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');

        $this->actingAs($user)
            ->get('/profile/email/confirm/'.$user->getKey().'/'.sha1('juan@lgu.gov.ph'))
            ->assertForbidden();

        $this->assertSame('juan@deped.gov.ph', $user->refresh()->email);
    }

    public function test_the_change_is_written_to_the_audit_trail(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');
        $this->actingAs($user)->get($this->confirmationUrl($user, 'juan@lgu.gov.ph'));

        $this->assertDatabaseHas('activity_logs', ['action' => 'email_change.requested']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'email_change.completed']);
    }

    /**
     * The gates are deliberately not in front of this. Somebody whose agency
     * inbox has died cannot verify the address they are trying to leave, and
     * gating the fix behind it would be a locked door with the key inside.
     */
    public function test_an_unverified_participant_can_still_change_their_address(): void
    {
        Notification::fake();

        $user = $this->participant(['email_verified_at' => null]);

        $this->actingAs($user)
            ->post('/profile/email', ['email' => 'juan@lgu.gov.ph', 'current_password' => 'Password123'])
            ->assertSessionHas('success');

        $this->assertSame('juan@lgu.gov.ph', $user->refresh()->pending_email);
    }

    public function test_the_profile_page_shows_a_pending_change(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');

        $this->actingAs($user)
            ->get('/profile')
            ->assertInertia(fn ($page) => $page->where('user.pending_email', 'juan@lgu.gov.ph'));
    }

    /** A stale one is not shown, or the card goes on pointing at a dead link. */
    public function test_an_expired_pending_change_is_not_shown(): void
    {
        Notification::fake();

        $user = $this->participant();
        $this->request($user, 'juan@lgu.gov.ph');

        $this->travel(EmailChangeService::LINK_TTL_MINUTES + 1)->minutes();

        $this->actingAs($user)
            ->get('/profile')
            ->assertInertia(fn ($page) => $page->where('user.pending_email', null));
    }

    public function test_moving_to_your_own_address_is_refused(): void
    {
        Notification::fake();

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/email', ['email' => 'juan@deped.gov.ph', 'current_password' => 'Password123'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_a_guest_cannot_request_a_change(): void
    {
        $this->post('/profile/email', ['email' => 'someone@lgu.gov.ph'])->assertRedirect(route('login'));
    }
}
