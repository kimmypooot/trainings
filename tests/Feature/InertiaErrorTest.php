<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An error during in-app navigation has to stay in the app.
 *
 * The application ships per-status Blade error pages and a branded Error.vue,
 * and only the `fallback` route ever reached the Vue one. Everything else — a
 * 403 from the role middleware, a 419 on an expired token, a 429, a 500 —
 * returned a full HTML document to a request that had asked for an Inertia
 * response. Inertia cannot use that, so it renders the document inside its own
 * error-modal overlay: a scrollable iframe of an error page, dropped on top of
 * the app somebody was in the middle of using.
 *
 * Two error experiences, and the worse one covered exactly the case a signed-in
 * user is most likely to meet.
 */
class InertiaErrorTest extends TestCase
{
    use RefreshDatabase;

    private function inertia(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
        ];
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    public function test_a_forbidden_inertia_visit_renders_the_error_component(): void
    {
        $this->actingAs($this->participant())
            ->get('/admin', $this->inertia())
            ->assertStatus(403)
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.status', 403);
    }

    public function test_a_missing_page_renders_the_error_component(): void
    {
        $this->actingAs($this->participant())
            ->get('/no-such-page', $this->inertia())
            ->assertStatus(404)
            ->assertJsonPath('component', 'Error');
    }

    /**
     * A plain browser visit is not running the SPA and has no shell to stay
     * inside, so it keeps the Blade page. Asserted so the fix cannot quietly
     * take the standalone error pages away.
     */
    public function test_a_plain_visit_still_gets_the_blade_page(): void
    {
        $response = $this->actingAs($this->participant())->get('/admin');

        $response->assertStatus(403);
        $this->assertStringContainsString('<!DOCTYPE html>', $response->getContent());
    }

    /**
     * 419 is a session that expired, not a page. Re-rendering the error
     * component would strand somebody on a dead end whose only honest action is
     * a reload; sending them back with a message lets the form be resubmitted
     * against a fresh token, which is what they were trying to do.
     */
    public function test_an_expired_session_sends_the_visitor_back_with_a_message(): void
    {
        /*
         * PreventRequestForgery short-circuits on `runningUnitTests()`, which
         * reads the environment rather than the middleware stack — so
         * withMiddleware() alone cannot reach this branch, and a test that used
         * only that would sail past the CSRF check into a plain credentials
         * failure and assert nothing about 419. Naming the environment is what
         * actually arms it.
         */
        $this->withMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'local');

        $response = $this->from('/login')->post('/login', [
            'email' => 'someone@example.test',
            'password' => 'whatever-they-typed',
            '_token' => 'a-stale-token',
        ], $this->inertia());

        $response->assertRedirect('/login');
        $response->assertSessionHas('error');
    }
}
