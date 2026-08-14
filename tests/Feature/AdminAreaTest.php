<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\TrainingLevel;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
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

    public function test_dashboard_modal_lists_are_withheld_until_asked_for(): void
    {
        $this->actingAs($this->staff())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Dashboard')
                ->missing('registrationsList')
                ->missing('awaitingCompletionList')
            );
    }

    public function test_dashboard_modal_lists_arrive_on_a_partial_reload(): void
    {
        $participant = $this->participant();
        $training = Training::factory()->create(['status' => TrainingStatus::Published]);
        app(RegistrationService::class)->register($participant, $training);

        $this->actingAs($this->staff());

        // A partial visit carrying the wrong asset version gets a 409, so ask
        // the middleware for the same value the real client would be holding.
        $version = app(HandleInertiaRequests::class)->version(request());

        // A partial visit answers with JSON rather than the root view, which
        // assertInertia cannot read — so assert on the payload directly.
        $response = $this->get('/admin', [
            'X-Inertia' => true,
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Admin/Dashboard',
            'X-Inertia-Partial-Data' => 'registrationsList',
        ])->assertOk();

        $response->assertJsonCount(1, 'props.registrationsList');
        $response->assertJsonPath('props.registrationsList.0.participant', $participant->name);
        $response->assertJsonPath('props.registrationsList.0.training', $training->title);

        // Only what was asked for comes back — the other dialog stays unqueried.
        $response->assertJsonMissingPath('props.awaitingCompletionList');
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

    /**
     * The fields carried over from v1's create form all survive the round trip.
     *
     * Grouped into one test because the risk here is a field being dropped from
     * the fillable list or the validated payload, which shows up identically
     * for every one of them.
     */
    public function test_creating_a_training_stores_the_v1_fields(): void
    {
        $this->actingAs($this->staff())
            ->post('/admin/trainings', [
                'title' => 'Supervisory Development Course',
                'venue' => 'Zoom',
                'venue_details' => 'Link is re-sent an hour before each session.',
                'meeting_link' => 'https://meet.google.com/abc-defg-hij',
                'mode' => TrainingMode::Hybrid->value,
                'level' => TrainingLevel::Intermediate->value,
                'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
                'ends_at' => now()->addWeek()->addHours(8)->format('Y-m-d\TH:i'),
                'capacity' => 25,
                'payment_required' => true,
                'payment_amount' => 1500,
                'accepts_promissory' => false,
                'is_supervisory' => true,
                'status' => TrainingStatus::Published->value,
            ])
            ->assertRedirect('/admin/trainings');

        $training = Training::first();

        $this->assertSame(TrainingLevel::Intermediate, $training->level);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $training->meeting_link);
        $this->assertSame('Link is re-sent an hour before each session.', $training->venue_details);
        $this->assertFalse($training->accepts_promissory);
        $this->assertTrue($training->is_supervisory);
    }

    public function test_online_and_hybrid_trainings_require_a_meeting_link(): void
    {
        foreach ([TrainingMode::Online, TrainingMode::Hybrid] as $mode) {
            $this->actingAs($this->staff())
                ->from('/admin/trainings/create')
                ->post('/admin/trainings', [
                    'title' => "Remote Run {$mode->value}",
                    'venue' => 'Zoom',
                    'mode' => $mode->value,
                    'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
                    'ends_at' => now()->addWeek()->addHours(8)->format('Y-m-d\TH:i'),
                    'status' => TrainingStatus::Draft->value,
                ])
                ->assertSessionHasErrors('meeting_link');
        }

        // Face-to-face has nobody dialling in, so the field stays optional.
        $this->actingAs($this->staff())
            ->post('/admin/trainings', [
                'title' => 'In Person Run',
                'venue' => 'CSC Regional Office',
                // Blank rather than absent: the select posts an empty string
                // when HRD has not decided on a level.
                'level' => '',
                'mode' => TrainingMode::FaceToFace->value,
                'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
                'ends_at' => now()->addWeek()->addHours(8)->format('Y-m-d\TH:i'),
                'status' => TrainingStatus::Draft->value,
            ])
            ->assertSessionHasNoErrors();
    }

    /** A link left behind by a mode change would be published as live. */
    public function test_switching_back_to_face_to_face_clears_the_meeting_link(): void
    {
        $training = Training::factory()->create([
            'mode' => TrainingMode::Online,
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        ]);

        $this->actingAs($this->staff())
            ->put("/admin/trainings/{$training->id}", [
                'title' => $training->title,
                'venue' => 'CSC Regional Office',
                'mode' => TrainingMode::FaceToFace->value,
                'meeting_link' => 'https://meet.google.com/abc-defg-hij',
                'starts_at' => $training->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $training->ends_at->format('Y-m-d\TH:i'),
                'status' => TrainingStatus::Published->value,
            ])
            ->assertRedirect('/admin/trainings');

        $this->assertNull($training->refresh()->meeting_link);
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

    private function registrationFor(Training $training, RegistrationStatus $status = RegistrationStatus::Approved): Registration
    {
        return Registration::factory()->create([
            'user_id' => $this->participant()->getKey(),
            'training_id' => $training->getKey(),
            'status' => $status,
        ]);
    }

    public function test_the_trainings_index_breaks_the_registered_column_down_by_fee_state(): void
    {
        $admin = $this->staff();
        $training = Training::factory()->create([
            'payment_required' => true,
            'payment_amount' => 1500,
            'capacity' => 20,
        ]);

        Payment::factory()->verified()->create([
            'registration_id' => $this->registrationFor($training)->getKey(),
            'payment_method' => PaymentMethod::Online,
        ]);

        Payment::factory()->verified()->create([
            'registration_id' => $this->registrationFor($training)->getKey(),
            'payment_method' => PaymentMethod::Promissory,
        ]);

        // Proof uploaded but not yet verified.
        Payment::factory()->create([
            'registration_id' => $this->registrationFor($training)->getKey(),
        ]);

        // No payment at all — still a slot-holder, so it counts as pending.
        $this->registrationFor($training);

        // Cancelled is reported apart from the total.
        $this->registrationFor($training, RegistrationStatus::Cancelled);

        $this->actingAs($admin)->get('/admin/trainings')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainings.data.0.registered', 4)
                ->where('trainings.data.0.paid', 1)
                ->where('trainings.data.0.promissory', 1)
                ->where('trainings.data.0.pending', 2)
                ->where('trainings.data.0.free', 0)
                ->where('trainings.data.0.cancelled', 1)
            );
    }

    public function test_free_trainings_only_count_in_the_free_bucket(): void
    {
        $admin = $this->staff();
        $training = Training::factory()->create(['payment_required' => false]);

        $this->registrationFor($training);
        $this->registrationFor($training);
        $this->registrationFor($training, RegistrationStatus::Cancelled);

        $this->actingAs($admin)->get('/admin/trainings')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainings.data.0.registered', 0)
                ->where('trainings.data.0.paid', 0)
                ->where('trainings.data.0.promissory', 0)
                ->where('trainings.data.0.pending', 0)
                ->where('trainings.data.0.free', 2)
                ->where('trainings.data.0.cancelled', 1)
            );
    }

    public function test_the_status_tabs_carry_the_catalogue_counts(): void
    {
        $admin = $this->staff();

        Training::factory()->create(['status' => TrainingStatus::Draft]);
        Training::factory()->create(['status' => TrainingStatus::Published]);
        Training::factory()->create(['status' => TrainingStatus::Published]);

        $this->actingAs($admin)->get('/admin/trainings')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tabs.0.label', 'All')
                ->where('tabs.0.count', 3)
                ->where('tabs.1.value', 'draft')
                ->where('tabs.1.count', 1)
                ->where('tabs.2.value', 'published')
                ->where('tabs.2.count', 2)
            );
    }
}
