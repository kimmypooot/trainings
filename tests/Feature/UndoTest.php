<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\RegistrationReviewed;
use App\Support\RegistrationService;
use App\Support\UndoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UndoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
    }

    private function registration(): Registration
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return RegistrationService::register($user->refresh(), Training::factory()->paid()->create());
    }

    public function test_a_review_offers_an_undo_token(): void
    {
        $registration = $this->registration();

        $this->actingAs($this->admin())
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'approved'])
            ->assertSessionHas('undo', fn (array $undo) => isset($undo['token'])
                && $undo['seconds'] === UndoService::WINDOW_SECONDS);
    }

    public function test_undo_restores_the_previous_status(): void
    {
        $registration = $this->registration();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'approved']);

        $this->assertSame(RegistrationStatus::Approved, $registration->refresh()->status);

        $token = session('undo')['token'];

        $this->actingAs($admin)->post('/admin/undo', ['token' => $token])->assertRedirect();

        $this->assertSame(RegistrationStatus::Pending, $registration->refresh()->status);
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $registration = $this->registration();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'approved']);

        $token = session('undo')['token'];

        $this->actingAs($admin)->post('/admin/undo', ['token' => $token]);

        // Re-approving, then replaying the old token, must not silently revert
        // the newer decision.
        $this->actingAs($admin)
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'approved']);

        $this->actingAs($admin)
            ->post('/admin/undo', ['token' => $token])
            ->assertSessionHasErrors('undo');

        $this->assertSame(RegistrationStatus::Approved, $registration->refresh()->status);
    }

    public function test_a_token_from_another_session_is_refused(): void
    {
        $registration = $this->registration();

        $this->actingAs($this->admin())
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'approved']);

        $token = session('undo')['token'];

        // A different staff member, with their own session, holds no such
        // snapshot — the token names nothing they can reach.
        $this->actingAs($this->admin())
            ->post('/admin/undo', ['token' => $token])
            ->assertSessionHasErrors('undo');

        $this->assertSame(RegistrationStatus::Approved, $registration->refresh()->status);
    }

    public function test_an_undone_decision_is_never_announced_to_the_participant(): void
    {
        Notification::fake();

        $registration = $this->registration();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'approved']);

        // The queued job is built at the moment of the decision, so build it
        // here — while the registration still says Approved — and only then
        // undo, which is the order the real queue sees.
        $approved = $registration->refresh();
        $notification = new RegistrationReviewed($approved);

        $token = session('undo')['token'];
        $this->actingAs($admin)->post('/admin/undo', ['token' => $token]);

        // Delivery re-checks the decision, so by the time the delayed job runs
        // there is nothing left to announce.
        $this->assertSame([], $notification->via($approved->user));
    }

    public function test_the_notification_still_goes_out_when_the_decision_stands(): void
    {
        $registration = $this->registration();

        $this->actingAs($this->admin())
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'approved']);

        $notification = new RegistrationReviewed($registration->refresh());

        $this->assertNotEmpty($notification->via($registration->user));
    }
}
