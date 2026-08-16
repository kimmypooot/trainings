<?php

namespace Tests\Feature;

use App\Models\Training;
use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->has('stats.0.figure')
                ->has('stats.0.label')
                ->where('stats.3.label', 'Regional offices')
            );
    }

    public function test_robots_served_by_route_and_points_at_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('User-agent: *', false)
            ->assertSee('Sitemap: '.url('/sitemap.xml'), false);
    }

    public function test_sitemap_lists_every_public_page(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<?xml version="1.0" encoding="UTF-8"?>', false);

        foreach (['/', '/login', '/register', '/forgot-password', '/privacy-policy', '/terms-of-service'] as $path) {
            $this->get('/sitemap.xml')
                ->assertSee(url($path), false);
        }
    }

    public function test_home_lists_upcoming_registrable_programs(): void
    {
        Training::factory()->create([
            'title' => 'Leadership Essentials',
            'starts_at' => now()->addDays(7),
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(3),
        ]);

        // A run whose registration window has already closed must not appear.
        Training::factory()->closed()->create(['title' => 'Too Late to Register']);

        // Drafts are not visible to participants.
        Training::factory()->draft()->create(['title' => 'Hidden Draft']);

        // A fully booked capped run has nothing left to offer on the landing page.
        Training::factory()->full()->create([
            'title' => 'No Slots Left',
            'starts_at' => now()->addDays(5),
        ]);

        // Past runs are not upcoming, even with a still-open window.
        Training::factory()->create([
            'title' => 'Already Ran',
            'starts_at' => now()->subDay(),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->has('upcomingTrainings', 1)
                ->where('upcomingTrainings.0.title', 'Leadership Essentials')
                ->where('upcomingTrainings.0.slots_remaining', 30)
            );
    }

    public function test_ga_measurement_id_is_emitted_only_when_configured(): void
    {
        config(['services.ga4.measurement_id' => null]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('data-ga-measurement-id', false);

        config(['services.ga4.measurement_id' => 'G-ABC123']);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-ga-measurement-id="G-ABC123"', false);
    }

    public function test_an_unknown_route_renders_the_branded_404(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Error')
                ->where('status', 404)
            );
    }

    public function test_login_page_renders_with_google_flag(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->has('googleEnabled')
            );
    }

    public function test_legal_pages_render(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/PrivacyPolicy'));

        $this->get('/terms-of-service')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/TermsOfService'));
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->from('/login')
            ->post('/login', [])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email', 'password']);
    }

    public function test_login_rejects_bad_credentials(): void
    {
        User::factory()->create(['email' => 'user@csc.gov.ph', 'password' => 'correct-password']);

        $this->from('/login')
            ->post('/login', ['email' => 'user@csc.gov.ph', 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('form');

        $this->assertGuest();
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'user@csc.gov.ph',
            'password' => 'correct-password',
        ]);

        $this->post('/login', ['email' => 'user@csc.gov.ph', 'password' => 'correct-password'])
            ->assertRedirect('/profile/complete');

        $this->assertAuthenticatedAs($user);
    }

    public function test_google_redirect_is_guarded_when_unconfigured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('form');
    }

    public function test_register_page_renders(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Register')
                ->has('googleEnabled')
            );
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/ForgotPassword')
            );
    }

    public function test_forgot_password_sends_a_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_never_reveals_whether_an_address_exists(): void
    {
        Notification::fake();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_reset_password_page_renders_with_the_token(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->get('/reset-password/'.$token.'?email='.$user->email)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/ResetPassword')
                ->where('token', $token)
                ->where('email', $user->email)
            );
    }

    public function test_a_password_can_be_reset(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->from('/reset-password/'.$token.'?email='.$user->email)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('NewPass123', $user->fresh()->password));
    }

    public function test_a_password_reset_requires_a_valid_token(): void
    {
        $user = User::factory()->create();

        $this->from('/reset-password/bogus-token?email='.$user->email)
            ->post('/reset-password', [
                'token' => 'bogus-token',
                'email' => $user->email,
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])
            ->assertRedirect('/reset-password/bogus-token?email='.$user->email)
            ->assertSessionHasErrors('form');

        $this->assertFalse(Hash::check('NewPass123', $user->fresh()->password));
    }

    public function test_registration_creates_and_signs_in_a_participant(): void
    {
        $this->post('/register', [
            'email' => 'juan@example.com',
            'password' => 'sikreto123',
            'password_confirmation' => 'sikreto123',
            'consent' => true,
        ])->assertRedirect('/profile/complete');

        $user = User::where('email', 'juan@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNull($user->name, 'The name is collected on the profile form, not at registration.');
        $this->assertNotSame('sikreto123', $user->password, 'Password must be hashed.');
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_validates_its_input(): void
    {
        $this->from('/register')
            ->post('/register', [
                'email' => 'not-an-email',
                'password' => 'short',
                'password_confirmation' => 'mismatch',
                'consent' => false,
            ])
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['email', 'password', 'consent']);

        $this->assertGuest();
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->from('/register')
            ->post('/register', [
                'email' => 'taken@example.com',
                'password' => 'sikreto123',
                'password_confirmation' => 'sikreto123',
                'consent' => true,
            ])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_visitor_count_is_shared_and_counted_once_per_session(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('visitors', 1));

        // Same session revisiting must not inflate the tally.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('visitors', 1));

        // A fresh session counts as a new visitor.
        $this->flushSession();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('visitors', 2));
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
