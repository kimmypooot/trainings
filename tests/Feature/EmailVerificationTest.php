<?php

namespace Tests\Feature;

use App\Models\FieldOffice;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_leaves_the_email_unverified_and_unsent(): void
    {
        Notification::fake();

        $this->post('/register', [
            'email' => 'juan@example.com',
            'password' => 'sikreto123',
            'password_confirmation' => 'sikreto123',
            'consent' => true,
        ])->assertRedirect('/profile/complete');

        $user = User::where('email', 'juan@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        // The link only goes out once the profile completes the registration —
        // a 60-minute link would go stale while the draftable gate form is open.
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_completing_the_profile_sends_the_verification_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->from('/profile/complete')
            ->post('/profile/complete', $this->validProfile())
            ->assertRedirect('/profile/complete');

        Notification::assertSentTo($user->refresh(), VerifyEmail::class);
    }

    public function test_an_unverified_user_is_blocked_from_the_participant_area(): void
    {
        $user = User::factory()->unverified()->create(['profile_completed_at' => now()]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/email/verify');
    }

    public function test_login_is_blocked_until_the_email_is_verified(): void
    {
        User::factory()->unverified()->create([
            'email' => 'done@example.com',
            'password' => 'sikreto123',
            'profile_completed_at' => now(),
        ]);

        // The login page is rendered directly (200) rather than a redirect back:
        // a browser drops the X-Inertia header when following the 302 to this
        // same URL, so the one-shot flash would be consumed by the intermediate
        // request and the "Email Not Verified" card would never appear. The
        // POST's own response carries the email.
        $this->from('/login')
            ->post('/login', ['email' => 'done@example.com', 'password' => 'sikreto123'])
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page->component('Auth/Login')
                    ->where('unverified_email', 'done@example.com')
            );

        $this->assertGuest();
    }

    public function test_an_unverified_user_without_a_profile_can_reach_the_gate_form(): void
    {
        User::factory()->unverified()->create([
            'email' => 'todo@example.com',
            'password' => 'sikreto123',
            'profile_completed_at' => null,
        ]);

        $this->post('/login', ['email' => 'todo@example.com', 'password' => 'sikreto123'])
            ->assertRedirect('/profile/complete');

        $this->assertAuthenticated();
    }

    public function test_the_verification_link_marks_the_email_verified(): void
    {
        $user = User::factory()->unverified()->create(['profile_completed_at' => now()]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/dashboard');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_verification_link_rejects_a_wrong_hash(): void
    {
        $user = User::factory()->unverified()->create(['profile_completed_at' => now()]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('someone-else@example.com'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_guest_clicking_the_link_is_sent_to_login_with_a_message(): void
    {
        $user = User::factory()->unverified()->create(['profile_completed_at' => now()]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($url)->assertRedirect('/login')->assertSessionHas('status');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_signed_in_user_can_resend_the_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['profile_completed_at' => now()]);

        $this->actingAs($user)
            ->from('/email/verify')
            ->post('/email/verification-notification')
            ->assertRedirect('/email/verify')
            ->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_login_screen_can_resend_the_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'email' => 'blocked@example.com',
            'profile_completed_at' => now(),
        ]);

        $this->from('/login')
            ->post('/email/resend', ['email' => 'blocked@example.com'])
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_gate_form_hands_off_to_the_success_state_when_unverified(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->from('/profile/complete')
            ->post('/profile/complete', $this->validProfile())
            ->assertRedirect('/profile/complete');

        // The page now carries the flag that swaps the form for the
        // "Registration Successful — verify your email" modal.
        $this->actingAs($user->refresh())
            ->get('/profile/complete')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile/Complete')
                ->where('registration_complete', true));
    }

    /** @return array<string, mixed> */
    private function validProfile(): array
    {
        return [
            'first_name' => 'Juan',
            'middle_name' => 'D',
            'last_name' => 'dela Cruz',
            'suffix' => 'JR.',
            'date_of_birth' => '1990-05-04',
            'sex' => 'Male',
            'is_pwd' => 'No',
            'civil_status' => 'Single',
            'mobile_number' => '09171234567',
            'position_title' => 'Administrative Officer III',
            'salary_grade' => 'SG 14',
            'organization_name' => 'Department of Education',
            'sector' => 'National Government Agency',
            'region' => 'Region VIII (Eastern Visayas)',
            'province' => 'Leyte',
            'city_municipality' => 'Palo',
            'field_office_id' => FieldOffice::where('code', 'lfoi')->value('id'),
            'position_level' => '2nd Level (Rank and File)',
            'employment_status' => 'Permanent',
            'organization_address' => 'Palo, Leyte',
            'consent' => true,
        ];
    }
}
