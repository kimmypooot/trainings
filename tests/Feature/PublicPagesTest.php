<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
