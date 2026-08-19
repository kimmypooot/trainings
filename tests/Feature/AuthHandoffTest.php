<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The auth preloader is a front-end effect, but the two things it cannot work
 * out for itself come from the server: the name it greets by, and the one-shot
 * flag that replays the welcome after a Google round trip. Both degrade
 * silently — a missing name just greets nobody — so they are pinned here.
 */
class AuthHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function participant(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => 'juan@example.com',
            'password' => Hash::make('Password123'),
            'profile_completed_at' => now(),
        ], $overrides));

        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    public function test_the_sign_in_flag_reaches_the_landing_page_and_no_further(): void
    {
        $this->participant();

        $this->followingRedirects()
            ->post('/login', ['email' => 'juan@example.com', 'password' => 'Password123'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('flash.just_logged_in', true)
            );

        // One-shot: the next page in the same session must not replay the beat.
        $this->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->where('flash.just_logged_in', null)
        );
    }

    public function test_the_landing_page_carries_the_name_the_splash_greets_by(): void
    {
        // Stored the way the app composes it — upper-cased, given name first.
        $this->participant(['name' => 'JUAN D. DELA CRUZ']);

        $this->followingRedirects()
            ->post('/login', ['email' => 'juan@example.com', 'password' => 'Password123'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.just_logged_in', true)
                ->where('auth.user.first_name', 'Juan')
            );
    }

    public function test_the_sign_in_flag_follows_a_participant_to_the_profile_gate(): void
    {
        $this->participant(['profile_completed_at' => null]);

        // The unfinished-profile landing is a standalone page rather than the
        // app shell, which is exactly why the hand-off is mounted app-wide.
        $this->followingRedirects()
            ->post('/login', ['email' => 'juan@example.com', 'password' => 'Password123'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile/Complete')
                ->where('flash.just_logged_in', true)
            );
    }

    public function test_staff_land_with_the_hand_off_too(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('Password123'),
            'role' => Role::Admin,
        ]);

        $this->followingRedirects()
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'Password123'])
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.just_logged_in', true));
    }

    public function test_signing_out_lands_on_the_public_home_page(): void
    {
        // The sign-out splash needs no flag of its own — it is app chrome and
        // survives the swap — but it does fade out over *this* page, so the
        // destination is part of the contract.
        $this->actingAs($this->participant())
            ->followingRedirects()
            ->post('/logout')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Home'));

        $this->assertGuest();
    }

    public function test_a_refused_sign_in_never_raises_the_hand_off(): void
    {
        $this->participant();

        // `from` because a refused sign-in redirects back, and without a
        // referer the test client's "back" is the home page.
        $this->from('/login')
            ->followingRedirects()
            ->post('/login', ['email' => 'juan@example.com', 'password' => 'wrong-password'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->where('flash.just_logged_in', null)
            );
    }
}
