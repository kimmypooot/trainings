<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TrainingStatus;
use App\Models\Profile;
use App\Models\Training;
use App\Models\User;
use App\Support\AttendanceService;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminAreaTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    private function participant(array $attributes = []): User
    {
        $user = User::factory()->create(['profile_completed_at' => now(), ...$attributes]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    public function test_participants_cannot_reach_the_admin_area(): void
    {
        $this->actingAs($this->participant())->get('/admin')->assertForbidden();
        $this->actingAs($this->participant())->get('/admin/trainings')->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_admin_dashboard_renders(): void
    {
        $this->actingAs($this->staff())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Dashboard')
                ->has('stats')
                ->has('upcoming')
            );
    }

    public function test_staff_are_not_held_by_the_participant_profile_gate(): void
    {
        $staff = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => null]);

        $this->actingAs($staff)->get('/admin')->assertOk();
    }

    public function test_staff_login_lands_on_the_admin_dashboard(): void
    {
        User::factory()->create([
            'email' => 'admin@csc.gov.ph',
            'password' => 'Password123',
            'role' => Role::Admin,
        ]);

        $this->post('/login', ['email' => 'admin@csc.gov.ph', 'password' => 'Password123'])
            ->assertRedirect('/admin');
    }

    public function test_admin_can_create_a_training(): void
    {
        $this->actingAs($this->staff())
            ->post('/admin/trainings', [
                'title' => 'Records Management Seminar',
                'description' => 'A seminar.',
                'venue' => 'CSC Central Office',
                'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
                'ends_at' => now()->addWeek()->addHours(8)->format('Y-m-d\TH:i'),
                'registration_closes_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
                'capacity' => 30,
                'status' => TrainingStatus::Published->value,
            ])
            ->assertRedirect('/admin/trainings')
            ->assertSessionHas('success');

        $training = Training::first();

        $this->assertSame('Records Management Seminar', $training->title);
        $this->assertSame('records-management-seminar', $training->slug);
        $this->assertSame(30, $training->capacity);
    }

    public function test_training_validation_rejects_an_end_before_the_start(): void
    {
        $this->actingAs($this->staff())
            ->from('/admin/trainings/create')
            ->post('/admin/trainings', [
                'title' => 'Bad Dates',
                'venue' => 'Somewhere',
                'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
                'ends_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
                'status' => TrainingStatus::Draft->value,
            ])
            ->assertRedirect('/admin/trainings/create')
            ->assertSessionHasErrors('ends_at');
    }

    public function test_admin_can_update_a_training(): void
    {
        $training = Training::factory()->create(['title' => 'Old Title']);

        $this->actingAs($this->staff())
            ->put("/admin/trainings/{$training->id}", [
                'title' => 'New Title',
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $training->ends_at->format('Y-m-d\TH:i'),
                'capacity' => 40,
                'status' => TrainingStatus::Published->value,
            ])
            ->assertRedirect('/admin/trainings');

        $this->assertSame('New Title', $training->fresh()->title);
        $this->assertSame(40, $training->fresh()->capacity);
    }

    public function test_roster_lists_registrations_and_food_restrictions(): void
    {
        $training = Training::factory()->create();
        $participant = $this->participant();
        $participant->profile->update([
            'food_restrictions_details' => 'NO PORK',
        ]);
        RegistrationService::register($participant, $training);

        $this->actingAs($this->staff())
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Trainings/Roster')
                ->has('registrations', 1)
                ->where('registrations.0.food_restrictions', 'NO PORK')
                ->where('summary.with_food_restrictions', 1)
            );
    }

    public function test_admin_can_approve_then_complete_a_registration(): void
    {
        // Runs today so the participant can actually be checked in — completion
        // now follows the attendance record rather than a bare staff decision.
        $training = Training::factory()->startingToday()->create();
        $registration = RegistrationService::register($this->participant(), $training);
        $staff = $this->staff();

        $this->assertSame('pending', $registration->status->value);

        // Completion is refused while the registration is still pending.
        $this->actingAs($staff)
            ->from("/admin/trainings/{$training->id}/roster")
            ->post("/admin/registrations/{$registration->id}/complete")
            ->assertSessionHasErrors('registration');

        $this->actingAs($staff)
            ->from("/admin/trainings/{$training->id}/roster")
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'approved'])
            ->assertRedirect("/admin/trainings/{$training->id}/roster");

        $registration->refresh();
        $this->assertSame('approved', $registration->status->value);
        $this->assertSame($staff->id, $registration->reviewed_by);
        $this->assertNotNull($registration->reviewed_at);

        AttendanceService::checkIn($registration, $staff);

        $this->actingAs($staff)
            ->from("/admin/trainings/{$training->id}/roster")
            ->post("/admin/registrations/{$registration->id}/complete")
            ->assertRedirect("/admin/trainings/{$training->id}/roster");

        $registration->refresh();
        $this->assertSame('completed', $registration->status->value);
        $this->assertNotNull($registration->attended_at);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $registration = RegistrationService::register($this->participant(), Training::factory()->create());

        $this->actingAs($this->staff())
            ->from('/admin/trainings')
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'rejected'])
            ->assertSessionHasErrors('remarks');

        $this->assertSame('pending', $registration->fresh()->status->value);
    }

    public function test_rejecting_frees_the_slot(): void
    {
        $training = Training::factory()->create(['capacity' => 1]);
        $registration = RegistrationService::register($this->participant(), $training);

        $this->assertSame(0, $training->fresh()->slotsRemaining());

        $this->actingAs($this->staff())
            ->from('/admin/trainings')
            ->post("/admin/registrations/{$registration->id}/review", [
                'decision' => 'rejected',
                'remarks' => 'Not eligible for this level.',
            ])
            ->assertSessionHas('success');

        $registration->refresh();

        $this->assertSame('rejected', $registration->status->value);
        $this->assertSame('Not eligible for this level.', $registration->review_remarks);
        // A rejected registration no longer holds a slot.
        $this->assertSame(1, $training->fresh()->slotsRemaining());
    }

    public function test_waitlisting_also_frees_the_slot(): void
    {
        $training = Training::factory()->create(['capacity' => 1]);
        $registration = RegistrationService::register($this->participant(), $training);

        $this->actingAs($this->staff())
            ->from('/admin/trainings')
            ->post("/admin/registrations/{$registration->id}/review", ['decision' => 'waitlisted']);

        $this->assertSame('waitlisted', $registration->fresh()->status->value);
        $this->assertSame(1, $training->fresh()->slotsRemaining());
    }

    public function test_participants_directory_searches_and_excludes_staff(): void
    {
        $this->participant(['email' => 'juan@example.com']);
        $this->staff();

        $this->actingAs($this->staff())
            ->get('/admin/participants')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Participants/Index')
                ->has('participants.data', 1)
            );
    }

    public function test_participant_detail_shows_training_history(): void
    {
        $participant = $this->participant();
        RegistrationService::register($participant, Training::factory()->create(['title' => 'Ethics 101']));

        $this->actingAs($this->staff())
            ->get("/admin/participants/{$participant->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Participants/Show')
                ->where('registrations.0.title', 'Ethics 101')
            );
    }

    public function test_field_office_and_management_cannot_edit_trainings(): void
    {
        $training = Training::factory()->create();

        foreach ([Role::FieldOffice, Role::Management] as $role) {
            $staff = $this->staff($role);

            // Read access is allowed…
            $this->actingAs($staff)->get('/admin/participants')->assertOk();
            $this->actingAs($staff)->get('/admin/trainings')->assertOk();
            $this->actingAs($staff)->get("/admin/trainings/{$training->id}/roster")->assertOk();

            // …but the pen is not.
            $this->actingAs($staff)->get('/admin/trainings/create')->assertForbidden();
            $this->actingAs($staff)->post('/admin/trainings', [])->assertForbidden();
            $this->actingAs($staff)->get("/admin/trainings/{$training->id}/edit")->assertForbidden();
        }
    }

    public function test_superadmin_can_edit_trainings(): void
    {
        $this->actingAs($this->staff(Role::SuperAdmin))
            ->get('/admin/trainings/create')
            ->assertOk();
    }
}
