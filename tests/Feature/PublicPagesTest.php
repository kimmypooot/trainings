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
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Home'));
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
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_google_redirect_is_guarded_when_unconfigured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('form');
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
