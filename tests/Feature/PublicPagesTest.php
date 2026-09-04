<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
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
                ->has('stats')
            );
    }

    /**
     * The headline figures band only publishes what the deployment can stand
     * behind.
     *
     * A fresh regional deployment has delivered nothing and completed nothing.
     * The band used to render that as "0 · 0 · 0% · 17" — three zeroes and a
     * hard-coded count of the *national* CSC organisation, which this database
     * has no standing to assert. "0% completion rate" on the front page of a
     * training portal is read as a statement about the office, not about the
     * data being new, so a figure with no denominator behind it is withheld
     * rather than shown as a zero.
     */
    public function test_home_withholds_headline_figures_it_cannot_stand_behind(): void
    {
        $labels = $this->headlineFigureLabels();

        // Nothing has been enrolled, delivered or completed yet, so none of
        // those three appear at all rather than appearing as a zero.
        $this->assertNotContains('Personnel enrolled', $labels);
        $this->assertNotContains('Programs delivered', $labels);
        $this->assertNotContains('Completion rate', $labels);

        // The nationwide count is gone for good: it was hard-coded, and this
        // database has no way to verify it. The field offices actually on the
        // system are countable, so that figure may legitimately stand.
        $this->assertNotContains('Regional offices', $labels);
    }

    /** @return array<int, string> */
    private function headlineFigureLabels(): array
    {
        $labels = [];

        $this->get('/')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$labels) {
                $labels = array_column($page->toArray()['props']['stats'], 'label');
            });

        return $labels;
    }

    public function test_home_publishes_a_completion_rate_once_there_is_a_denominator(): void
    {
        $training = Training::factory()->create(['starts_at' => now()->subDays(10)]);
        $user = User::factory()->create(['profile_completed_at' => now()]);

        Registration::factory()->for($training)->for($user)->create([
            'status' => RegistrationStatus::Completed,
        ]);

        $labels = $this->headlineFigureLabels();

        $this->assertContains('Completion rate', $labels);

        // Never the nationwide figure this database cannot count.
        $this->assertNotContains('Regional offices', $labels);
    }

    /**
     * The accessibility statement is a page, not a promise inside a policy.
     * A visitor using assistive technology needs somewhere to read what the
     * site claims to conform to and where to report a barrier.
     */
    public function test_accessibility_statement_is_public(): void
    {
        $this->get('/accessibility')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Accessibility'));
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

        $paths = [
            '/',
            '/login',
            '/register',
            '/forgot-password',
            '/privacy-policy',
            '/terms-of-service',
            '/accessibility',
            // A public entry point in its own right: an employer searching for
            // how to check a CSC certificate should be able to land on it
            // without holding a code first.
            '/verify',
        ];

        foreach ($paths as $path) {
            $this->get('/sitemap.xml')
                ->assertSee(url($path), false);
        }
    }

    /**
     * The landing page is a catalogue of what the office is offering, not a
     * list of what an anonymous visitor could join this second.
     *
     * So a run that is full, not yet open for registration, past its deadline,
     * or already under way still gets listed — each one labelled with where it
     * sits in its lifecycle. Only a draft (never published) and a finished run
     * are genuinely absent. The earlier version of this test asserted the
     * opposite, and that strictness was the bug: most of the office's calendar
     * never reached the public page at all.
     */
    public function test_home_lists_every_unfinished_published_program_with_its_status(): void
    {
        Training::factory()->create([
            'title' => 'Leadership Essentials',
            'starts_at' => now()->addDays(30),
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(20),
        ]);

        // Past its deadline, but the run itself has not happened yet: still
        // announced, and honest about why nobody can sign up.
        Training::factory()->closed()->create([
            'title' => 'Too Late to Register',
            'starts_at' => now()->addDays(10),
        ]);

        // Drafts are not visible to participants.
        Training::factory()->draft()->create(['title' => 'Hidden Draft']);

        Training::factory()->full()->create([
            'title' => 'No Slots Left',
            'starts_at' => now()->addDays(5),
        ]);

        // Announced ahead of its registration window opening — the case the old
        // query dropped silently.
        Training::factory()->create([
            'title' => 'Opens Later',
            'starts_at' => now()->addDays(60),
            'registration_opens_at' => now()->addDays(20),
        ]);

        // Under way: started this morning, runs for another two days.
        Training::factory()->create([
            'title' => 'Halfway Through',
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->addDays(2),
        ]);

        // Genuinely over, so genuinely gone.
        Training::factory()->create([
            'title' => 'Already Ran',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subDays(4),
        ]);

        $response = $this->get('/')->assertOk();

        $listed = collect($response->viewData('page')['props']['programs'])
            ->keyBy('title');

        $this->assertEqualsCanonicalizing([
            'Halfway Through',
            'No Slots Left',
            'Too Late to Register',
            'Leadership Essentials',
            'Opens Later',
        ], $listed->keys()->all());

        $this->assertSame('ongoing', $listed['Halfway Through']['status']);
        $this->assertSame('full', $listed['No Slots Left']['status']);
        $this->assertSame('closed', $listed['Too Late to Register']['status']);
        $this->assertSame('open', $listed['Leadership Essentials']['status']);
        $this->assertSame('opening', $listed['Opens Later']['status']);

        // Only the genuinely joinable one invites a visitor to sign in.
        $this->assertSame(
            ['Leadership Essentials'],
            $listed->filter(fn (array $t) => $t['is_registrable'])->keys()->all()
        );

        $this->assertSame(30, $listed['Leadership Essentials']['slots_remaining']);
    }

    /** A deadline inside the next week earns the one bit of urgency the card has. */
    public function test_home_flags_a_program_whose_registration_closes_within_a_week(): void
    {
        Training::factory()->create([
            'title' => 'Closing Soon',
            'starts_at' => now()->addDays(14),
            'registration_closes_at' => now()->addDays(3),
        ]);

        $response = $this->get('/')->assertOk();
        $listed = $response->viewData('page')['props']['programs'][0];

        $this->assertSame('closing-soon', $listed['status']);
        // Still joinable — "closing soon" is a hint, not a different permission.
        $this->assertTrue($listed['is_registrable']);
    }

    /**
     * The section is the catalogue, not a teaser for one.
     *
     * It used to stop at six and link out to /programs for the rest. That page
     * is gone, so a cap here would strand the remainder with nowhere to send a
     * visitor — nine programs must all be on the first page, and only the
     * paginator may hold anything back.
     */
    public function test_home_lists_the_whole_first_page_of_programs(): void
    {
        Training::factory()->count(9)->create(['starts_at' => now()->addDays(30)]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->has('programs', 9)
                ->where('meta.last_page', 1)
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
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ])
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_a_password_reset_requires_a_valid_token(): void
    {
        $user = User::factory()->create();

        $this->from('/reset-password/bogus-token?email='.$user->email)
            ->post('/reset-password', [
                'token' => 'bogus-token',
                'email' => $user->email,
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ])
            ->assertRedirect('/reset-password/bogus-token?email='.$user->email)
            ->assertSessionHasErrors('form');

        $this->assertFalse(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_registration_creates_and_signs_in_a_participant(): void
    {
        $this->post('/register', [
            'email' => 'juan@example.com',
            'password' => 'sikretokong123',
            'password_confirmation' => 'sikretokong123',
            'consent' => true,
        ])->assertRedirect('/profile/complete');

        $user = User::where('email', 'juan@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNull($user->name, 'The name is collected on the profile form, not at registration.');
        $this->assertNotSame('sikretokong123', $user->password, 'Password must be hashed.');
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
                'password' => 'sikretokong123',
                'password_confirmation' => 'sikretokong123',
                'consent' => true,
            ])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * A participant who first arrived through Google has no password to
     * remember and no reason to expect they are already registered. The bare
     * uniqueness message left them on the form retrying; this one points at the
     * button that would sign them in.
     */
    public function test_a_google_only_account_is_told_to_sign_in_with_google(): void
    {
        User::factory()->create([
            'email' => 'juan@gmail.com',
            'password' => null,
            'google_id' => 'google-123',
        ]);

        $this->from('/register')
            ->post('/register', [
                'email' => 'juan@gmail.com',
                'password' => 'sikretokong123',
                'password_confirmation' => 'sikretokong123',
                'consent' => true,
            ])
            ->assertSessionHasErrorsIn('default', [
                'email' => 'You already have an account with this email address. Use the '.
                    '"Continue with Google" button above to sign in.',
            ]);

        $this->assertGuest();
    }

    /**
     * An account that has a password is told to sign in, not to use Google —
     * pointing at Google here would send them somewhere that does not work.
     */
    public function test_an_account_with_a_password_is_told_to_sign_in(): void
    {
        User::factory()->create([
            'email' => 'juan@deped.gov.ph',
            'google_id' => 'google-123',
        ]);

        $this->from('/register')
            ->post('/register', [
                'email' => 'juan@deped.gov.ph',
                'password' => 'sikretokong123',
                'password_confirmation' => 'sikretokong123',
                'consent' => true,
            ])
            ->assertSessionHasErrorsIn('default', [
                'email' => 'An account with this email address already exists. Sign in instead, or use '.
                    '"Forgot password" if you cannot remember it.',
            ]);

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
