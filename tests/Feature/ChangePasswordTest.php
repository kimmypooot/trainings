<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->create([
            'role' => Role::Participant,
            'profile_completed_at' => now(),
        ]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->post('/change-password', [
            'current_password' => 'password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertRedirect('/login');
    }

    public function test_a_signed_in_user_can_rotate_their_password(): void
    {
        $user = $this->actor();

        $this->actingAs($user)->post('/change-password', [
            'current_password' => 'password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewPass123', $user->fresh()->password));
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $user = $this->actor();

        $this->actingAs($user)
            ->from('/dashboard')
            ->post('/change-password', [
                'current_password' => 'not-the-password',
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_new_password_must_meet_the_policy(): void
    {
        $user = $this->actor();

        $this->actingAs($user)->post('/change-password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_confirmation_must_match(): void
    {
        $user = $this->actor();

        $this->actingAs($user)->post('/change-password', [
            'current_password' => 'password',
            'password' => 'NewPass123',
            'password_confirmation' => 'Different123',
        ])->assertSessionHasErrors('password');
    }

    public function test_staff_without_a_profile_can_change_their_password(): void
    {
        // The route must not sit behind the profile-completeness gate: staff
        // are never asked to complete a participant profile.
        $staff = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($staff)->post('/change-password', [
            'current_password' => 'password',
            'password' => 'StaffPass123',
            'password_confirmation' => 'StaffPass123',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('StaffPass123', $staff->fresh()->password));
    }

    /*
     * ── Google-created accounts ───────────────────────────────────────────
     *
     * These have no password to re-enter. Email sign-in is still open to them;
     * they just have not chosen a password yet, and this is where they do.
     */

    private function googleOnlyUser(): User
    {
        return User::factory()->create([
            'role' => Role::Participant,
            'profile_completed_at' => now(),
            'password' => null,
            'google_id' => 'google-123',
            'google_email' => 'juan@gmail.com',
        ]);
    }

    public function test_a_google_account_can_create_a_first_password(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)->post('/change-password', [
            'password' => 'FirstPass123',
            'password_confirmation' => 'FirstPass123',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('FirstPass123', $user->fresh()->password));
    }

    /**
     * The payload the dialog actually sends.
     *
     * useForm keeps `current_password` in its data even while the field is
     * hidden, so the request carries it as an empty string — which
     * ConvertEmptyStringsToNull turns into null before the rules run. Every
     * other test here omits the key entirely, and an *absent* attribute skips
     * non-implicit rules while a *present but null* one does not. That gap is
     * what let 'string' reject the null and fail the whole change, with the
     * message pinned to a field the creating dialog never renders.
     */
    public function test_a_google_account_can_create_a_first_password_with_the_forms_empty_current_password(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)->post('/change-password', [
            'current_password' => '',
            'password' => 'FirstPass123',
            'password_confirmation' => 'FirstPass123',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertTrue(Hash::check('FirstPass123', $user->fresh()->password));
    }

    /**
     * The same empty string must not become a way past the check for an account
     * that does have a password — 'required' still has to fire on the null.
     */
    public function test_an_empty_current_password_still_fails_for_an_ordinary_account(): void
    {
        $user = $this->actor();

        $this->actingAs($user)->post('/change-password', [
            'current_password' => '',
            'password' => 'SneakyPass123',
            'password_confirmation' => 'SneakyPass123',
        ])->assertSessionHasErrors('current_password');

        $this->assertFalse(Hash::check('SneakyPass123', $user->fresh()->password));
    }

    /**
     * Creating the first password is what opens email sign-in; the Google
     * connection is untouched, so both ways in now work.
     */
    public function test_creating_a_password_leaves_google_connected(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)->post('/change-password', [
            'password' => 'FirstPass123',
            'password_confirmation' => 'FirstPass123',
        ]);

        $user->refresh();

        $this->assertSame('google-123', $user->google_id);
        $this->assertTrue($user->hasPassword());
    }

    /**
     * Once a password exists the account is an ordinary one, and the
     * current-password check is back in force.
     */
    public function test_the_second_change_requires_the_password_just_created(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)->post('/change-password', [
            'password' => 'FirstPass123',
            'password_confirmation' => 'FirstPass123',
        ]);

        $this->actingAs($user->fresh())->post('/change-password', [
            'password' => 'SecondPass123',
            'password_confirmation' => 'SecondPass123',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('FirstPass123', $user->fresh()->password));
    }

    /**
     * The exemption is decided by the stored password, never by the request —
     * omitting the field must not let an ordinary account skip the check.
     */
    public function test_an_account_with_a_password_cannot_skip_the_check_by_omitting_it(): void
    {
        $user = $this->actor();

        $this->actingAs($user)->post('/change-password', [
            'password' => 'SneakyPass123',
            'password_confirmation' => 'SneakyPass123',
        ])->assertSessionHasErrors('current_password');

        $this->assertFalse(Hash::check('SneakyPass123', $user->fresh()->password));
    }

    public function test_a_first_password_still_meets_the_policy(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)->post('/change-password', [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->password);
    }

    /*
     * ── The "your sign-in details changed" notice ─────────────────────────
     *
     * Sent to the address on the account, which is the one place a hijacked
     * session cannot reach.
     */

    public function test_creating_a_first_password_notifies_the_account_holder(): void
    {
        Notification::fake();

        $user = $this->googleOnlyUser();

        $this->actingAs($user)->post('/change-password', [
            'password' => 'FirstPass123',
            'password_confirmation' => 'FirstPass123',
        ]);

        Notification::assertSentTo(
            $user,
            PasswordChanged::class,
            // The added-a-way-in wording, not the rotated-it wording.
            fn (PasswordChanged $notification) => str_contains(
                $notification->toMail($user)->subject,
                'A password was added'
            )
        );
    }

    public function test_rotating_a_password_also_notifies(): void
    {
        Notification::fake();

        $user = $this->actor();

        $this->actingAs($user)->post('/change-password', [
            'current_password' => 'password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ]);

        Notification::assertSentTo(
            $user,
            PasswordChanged::class,
            fn (PasswordChanged $notification) => str_contains(
                $notification->toMail($user)->subject,
                'was changed'
            )
        );
    }

    /**
     * End to end, without faking the notification layer: the mail is actually
     * built, rendered, and handed to the mailer in the same request. Catches a
     * template that only breaks outside Notification::fake().
     */
    public function test_the_notice_is_really_sent_and_names_the_recipient(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)->post('/change-password', [
            'password' => 'FirstPass123',
            'password_confirmation' => 'FirstPass123',
        ])->assertSessionHas('success');

        $sent = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $sent);

        $message = $sent[0]->getOriginalMessage();

        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('A password was added', $message->getSubject());
        $this->assertStringContainsString('Reset my password', $message->getHtmlBody());
    }

    public function test_a_rejected_attempt_notifies_nobody(): void
    {
        Notification::fake();

        $user = $this->actor();

        $this->actingAs($user)->post('/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertSessionHasErrors('current_password');

        Notification::assertNothingSent();
    }

    /**
     * With a password made, disconnecting Google is no longer refused — the
     * dead end the account menu used to point into.
     */
    public function test_disconnecting_google_works_once_a_password_exists(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)->delete('/profile/google')->assertSessionHas('error');

        $this->actingAs($user)->post('/change-password', [
            'password' => 'FirstPass123',
            'password_confirmation' => 'FirstPass123',
        ]);

        $this->actingAs($user->fresh())->delete('/profile/google')->assertSessionHas('success');

        $this->assertNull($user->fresh()->google_id);
    }
}
