<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\CancellationRequest;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\CancellationRequestService;
use App\Support\RegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TrainingRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function participant(array $attributes = []): User
    {
        $user = User::factory()->create(['profile_completed_at' => now(), ...$attributes]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    public function test_catalogue_lists_only_published_upcoming_trainings(): void
    {
        $published = Training::factory()->create(['title' => 'Records Management']);
        Training::factory()->draft()->create(['title' => 'Hidden Draft']);
        Training::factory()->create([
            'title' => 'Past Event',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHours(6),
        ]);

        $this->actingAs($this->participant())
            ->get('/trainings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trainings/Index')
                ->has('trainings.data', 1)
                ->where('trainings.data.0.title', $published->title)
            );
    }

    public function test_draft_training_detail_is_not_found(): void
    {
        $training = Training::factory()->draft()->create();

        $this->actingAs($this->participant())
            ->get("/trainings/{$training->slug}")
            ->assertNotFound();
    }

    public function test_participant_can_register_and_slot_is_consumed(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create(['capacity' => 2]);

        $this->actingAs($user)
            ->from("/trainings/{$training->slug}")
            ->post("/trainings/{$training->id}/register")
            ->assertRedirect("/trainings/{$training->slug}")
            ->assertSessionHas('success');

        $this->assertDatabaseHas('registrations', [
            'user_id' => $user->id,
            'training_id' => $training->id,
            'status' => RegistrationStatus::Pending->value,
        ]);

        $this->assertSame(1, $training->fresh()->slotsRemaining());
    }

    public function test_duplicate_registration_is_refused(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create();

        RegistrationService::register($user, $training);

        $this->expectException(ValidationException::class);
        RegistrationService::register($user, $training);
    }

    public function test_database_blocks_a_second_row_for_the_same_pair(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create();

        Registration::factory()->create(['user_id' => $user->id, 'training_id' => $training->id]);

        $this->expectException(QueryException::class);
        Registration::factory()->create(['user_id' => $user->id, 'training_id' => $training->id]);
    }

    public function test_registration_is_refused_when_full(): void
    {
        $training = Training::factory()->create(['capacity' => 1]);
        RegistrationService::register($this->participant(['email' => 'first@example.com']), $training);

        $this->expectException(ValidationException::class);
        RegistrationService::register($this->participant(['email' => 'second@example.com']), $training);
    }

    public function test_unlimited_capacity_never_fills(): void
    {
        $training = Training::factory()->unlimited()->create();

        foreach (range(1, 3) as $index) {
            RegistrationService::register($this->participant(['email' => "p{$index}@example.com"]), $training);
        }

        $this->assertFalse($training->fresh()->isFull());
        $this->assertNull($training->fresh()->slotsRemaining());
    }

    public function test_registration_is_refused_after_the_deadline(): void
    {
        $training = Training::factory()->closed()->create();

        $this->expectException(ValidationException::class);
        RegistrationService::register($this->participant(), $training);
    }

    public function test_cancelling_frees_the_slot_and_allows_re_registration(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create(['capacity' => 1]);

        $registration = RegistrationService::register($user, $training);
        $this->assertSame(0, $training->fresh()->slotsRemaining());

        // Withdrawal now goes through review, so the slot is only freed once
        // staff approve — see RequestWorkflowTest for the queue itself.
        $this->actingAs($user)
            ->from('/my/registrations')
            ->delete("/my/registrations/{$registration->id}", ['reason' => 'Conflicting assignment.'])
            ->assertRedirect('/my/registrations')
            ->assertSessionHas('success');

        $this->assertSame(0, $training->fresh()->slotsRemaining());

        CancellationRequestService::review(
            CancellationRequest::sole(),
            RequestStatus::Approved,
            User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()])
        );

        $this->assertSame(1, $training->fresh()->slotsRemaining());

        // Re-registering reuses the same row, so the unique constraint holds.
        $again = RegistrationService::register($user->fresh(), $training->fresh());

        $this->assertSame($registration->id, $again->id);
        $this->assertSame(RegistrationStatus::Pending, $again->status);
        $this->assertSame(1, Registration::where('user_id', $user->id)->count());
    }

    public function test_cannot_cancel_another_participants_registration(): void
    {
        $owner = $this->participant(['email' => 'owner@example.com']);
        $registration = RegistrationService::register($owner, Training::factory()->create());

        $this->actingAs($this->participant(['email' => 'other@example.com']))
            ->delete("/my/registrations/{$registration->id}", ['reason' => 'Not mine to cancel.'])
            ->assertForbidden();
    }

    public function test_my_registrations_lists_the_participants_own_records(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create(['title' => 'Leadership Program']);
        RegistrationService::register($user, $training);

        $this->actingAs($user)
            ->get('/my/registrations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('My/Registrations')
                ->has('registrations', 1)
                ->where('registrations.0.training.title', 'Leadership Program')
            );
    }

    public function test_dashboard_surfaces_the_next_training(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create([
            'title' => 'Public Service Ethics',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(6),
        ]);
        RegistrationService::register($user, $training);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nextTraining.title', 'Public Service Ethics')
                // A fresh registration is pending, not yet approved.
                ->where('summary.pending', 1)
                ->where('summary.registered', 0)
                ->has('recentActivity', 1)
            );
    }

    public function test_certificates_and_notifications_pages_render(): void
    {
        $user = $this->participant();

        $this->actingAs($user)->get('/my/certificates')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('My/Certificates'));

        $this->actingAs($user)->get('/notifications')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Notifications/Index'));
    }
}
