<?php

namespace Tests\Feature;

use App\Enums\ChargeTo;
use App\Enums\RegistrationStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CancellationRequest;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\CancellationRequestService;
use App\Support\RegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $published = Training::factory()->create([
            'title' => 'Records Management',
            'ends_at' => now()->addDays(2),
        ]);
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
                ->where('trainings.data.0.ends_at', $published->ends_at->format('d M Y'))
            );
    }

    /**
     * A run that has started but not finished still belongs in the catalogue.
     *
     * The listing used to filter on `upcoming()` — `starts_at >= now()` —
     * which drops a multi-day course on the morning of day one, while it is
     * still being delivered. The calendar had already moved to the overlap
     * rule, so the two pages disagreed about the same training: it appeared on
     * every day of its run in the calendar and nowhere in Browse.
     */
    public function test_the_catalogue_keeps_a_run_that_has_started_but_not_ended(): void
    {
        $running = Training::factory()->startingToday()->runningFor(3)->create([
            'title' => 'Basic Computer Literacy',
        ]);

        Training::factory()->create([
            'title' => 'Finished Yesterday',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($this->participant())
            ->get('/trainings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The one still running, never the one already over.
                ->has('trainings.data', 1)
                ->where('trainings.data.0.title', $running->title)
            );
    }

    public function test_a_running_training_can_still_open_its_details(): void
    {
        $running = Training::factory()->startingToday()->runningFor(3)->create([
            'title' => 'Basic Computer Literacy',
        ]);

        // The modal fetches through its own query. A window narrower than the
        // listing's would render a card whose View Details comes back empty.
        $this->actingAs($this->participant())
            ->get('/trainings?details='.$running->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('details.title', $running->title)
                ->etc()
            );
    }

    public function test_draft_training_detail_is_not_found(): void
    {
        $training = Training::factory()->draft()->create();

        $this->actingAs($this->participant())
            ->get("/trainings/{$training->slug}")
            ->assertNotFound();
    }

    public function test_catalogue_filters_by_mode(): void
    {
        Training::factory()->create(['title' => 'On the Grid', 'mode' => TrainingMode::Online]);
        Training::factory()->create(['title' => 'In the Room', 'mode' => TrainingMode::FaceToFace]);

        $this->actingAs($this->participant())
            ->get('/trainings?mode=online')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trainings/Index')
                ->has('trainings.data', 1)
                ->where('trainings.data.0.title', 'On the Grid')
                ->where('filters.mode', 'online')
            );
    }

    public function test_catalogue_search_reaches_title_code_and_venue(): void
    {
        $target = Training::factory()->create(['training_code' => 'TRN-SPECIAL-1', 'title' => 'Capability Program']);
        Training::factory()->create(['training_code' => 'TRN-OTHER-9', 'title' => 'Something Else']);

        $this->actingAs($this->participant())
            ->get('/trainings?search=TRN-SPECIAL')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('trainings.data', 1)
                ->where('trainings.data.0.id', $target->id)
                ->where('filters.search', 'TRN-SPECIAL')
            );
    }

    public function test_catalogue_open_filter_hides_trainings_whose_window_is_shut(): void
    {
        // Registration window closed in the past.
        Training::factory()->closed()->create(['title' => 'Too Late']);
        // Registration window not yet open.
        Training::factory()->create([
            'title' => 'Not Yet',
            'registration_opens_at' => now()->addWeek(),
            'registration_closes_at' => now()->addMonth(),
        ]);
        Training::factory()->create(['title' => 'Open Now']);

        $this->actingAs($this->participant())
            ->get('/trainings?open=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('trainings.data', 1)
                ->where('trainings.data.0.title', 'Open Now')
            );
    }

    public function test_catalogue_sorts_by_registration_deadline(): void
    {
        $start = now()->addWeek();

        Training::factory()->create([
            'title' => 'Later Closer',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(6),
            'registration_closes_at' => $start->copy()->subDay(),
        ]);
        Training::factory()->create([
            'title' => 'Sooner Closer',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(6),
            'registration_closes_at' => now()->addDays(2),
        ]);

        $this->actingAs($this->participant())
            ->get('/trainings?sort=closing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainings.data.0.title', 'Sooner Closer')
                ->where('trainings.data.1.title', 'Later Closer')
                ->where('filters.sort', 'closing')
            );
    }

    public function test_catalogue_card_carries_the_registration_status(): void
    {
        $user = $this->participant();
        // Charged, so the registration waits at pending — a free run would be
        // approved on the spot and this card would show that instead.
        $training = Training::factory()->create([
            'title' => 'Status Check',
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);
        RegistrationService::register($user, $training);

        $this->actingAs($user)
            ->get('/trainings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainings.data.0.is_registered', true)
                ->where('trainings.data.0.registration_status', RegistrationStatus::Pending->value)
                ->where('registeredCount', 1)
            );
    }

    public function test_catalogue_ships_no_detail_by_default(): void
    {
        Training::factory()->create();

        $this->actingAs($this->participant())
            ->get('/trainings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trainings/Index')
                ->where('details', null)
            );
    }

    public function test_catalogue_detail_modal_loads_on_demand(): void
    {
        $training = Training::factory()->create([
            'title' => 'Deep Dive',
            'description' => 'Everything about the modality.',
            'venue_details' => 'Room 4, ground floor.',
            'prerequisites' => 'A laptop and a stable connection.',
        ]);

        $this->actingAs($this->participant())
            ->get('/trainings?details='.$training->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trainings/Index')
                ->where('details.id', $training->id)
                ->where('details.title', $training->title)
                ->where('details.description', 'Everything about the modality.')
                ->where('details.venue_details', 'Room 4, ground floor.')
                ->where('details.prerequisites', 'A laptop and a stable connection.')
            );
    }

    // The modal's partial reload asks for exactly one prop. Simulating the
    // headers Inertia sends confirms the response carries `details` and not the
    // card list — a full second payload would defeat the whole point.
    public function test_catalogue_detail_partial_reload_ships_only_details(): void
    {
        $training = Training::factory()->create([
            'title' => 'Partial Only',
            'description' => 'Arrives on demand.',
        ]);

        $this->actingAs($this->participant())
            ->get('/trainings?details='.$training->id, [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Data' => 'details',
                'X-Inertia-Partial-Component' => 'Trainings/Index',
                // Ask the middleware for the version rather than hashing the
                // manifest here: that is the value a real client holds, and it
                // still resolves when there is no build on disk — which is
                // every CI run, since the PHP job never builds assets.
                //
                // The (string) cast is the load-bearing part. With no manifest
                // version() is null, and passing null as a header value sends
                // the header present-but-null; Inertia compares it against
                // (string) $this->version(), so null !== '' and the visit gets
                // a 409 asset-version bounce instead of the payload.
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            ])
            ->assertOk()
            ->assertJsonPath('component', 'Trainings/Index')
            ->assertJsonPath('props.details.id', $training->id)
            ->assertJsonPath('props.details.description', 'Arrives on demand.')
            ->assertJsonMissingPath('props.trainings');
    }

    public function test_participant_can_register_and_slot_is_consumed(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create(['capacity' => 2]);

        $this->actingAs($user)
            ->from("/trainings/{$training->slug}")
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertRedirect("/trainings/{$training->slug}")
            ->assertSessionHas('success');

        $this->assertDatabaseHas('registrations', [
            'user_id' => $user->id,
            'training_id' => $training->id,
            // Free training, so the slot is confirmed on the spot — first
            // come, first served, with nothing left to settle.
            'status' => RegistrationStatus::Approved->value,
            'charge_to' => ChargeTo::Personal->value,
            'needs_certificate' => true,
        ]);

        $this->assertSame(1, $training->fresh()->slotsRemaining());
    }

    /*
     * ── What the participant is told afterwards ───────────────────────────
     *
     * The message used to be one fixed sentence about awaiting CSC approval,
     * which was wrong on a free run (already approved) and unhelpful on a paid
     * one (the fee, not a person, is what the slot is waiting on).
     */

    public function test_a_free_registration_is_not_reported_as_awaiting_approval(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create(['payment_required' => false]);

        $this->actingAs($user)
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'You are registered')
                && ! str_contains($message, 'awaiting approval')
            );

        $this->assertSame(RegistrationStatus::Approved, Registration::sole()->status);
    }

    public function test_a_paid_registration_lands_on_the_payments_page(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create([
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);

        $this->actingAs($user)
            ->from("/trainings/{$training->slug}")
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
            ])
            // Not back to the catalogue: the slot is held, not seated, and
            // settling the fee is the next thing to actually do.
            ->assertRedirect('/my/payments')
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '1,500.00')
                && str_contains($message, 'confirm your slot')
            );

        $this->assertSame(RegistrationStatus::Pending, Registration::sole()->status);
    }

    /**
     * And the page it lands on is already showing the thing it sent them for —
     * a redirect into an empty list would be worse than the message was.
     */
    public function test_the_payments_page_is_already_showing_that_registration(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create([
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);

        $this->actingAs($user)->post("/trainings/{$training->id}/register", [
            'charge_to' => ChargeTo::Personal->value,
        ]);

        $this->actingAs($user)
            ->get('/my/payments')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('My/Payments')
                ->has('awaitingPayment', 1)
                ->where('awaitingPayment.0.training.title', $training->title)
            );
    }

    /** A free run has nothing to settle, so it stays where it was. */
    public function test_a_free_registration_does_not_divert_to_payments(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create(['payment_required' => false]);

        $this->actingAs($user)
            ->from("/trainings/{$training->slug}")
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
            ])
            ->assertRedirect("/trainings/{$training->slug}");
    }

    /**
     * The catalogue modal registers on the spot rather than linking to the
     * detail page, so the page has to ship what that form needs: the payee
     * options, and the eligibility of *this* participant for the training whose
     * card was opened.
     */
    public function test_the_catalogue_ships_what_the_modal_registration_form_needs(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create();

        $this->actingAs($user)
            ->get("/trainings?details={$training->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trainings/Index')
                ->has('chargeOptions', count(ChargeTo::cases()))
                ->has('details.eligibility', fn (AssertableInertia $eligibility) => $eligibility
                    ->has('barred')
                    ->has('barred_reason')
                    ->has('needs_supporting_document')
                    ->has('supporting_document_hint')
                )
            );
    }

    /**
     * The payee has no default any more — not on the form, and not here. A
     * submission that never answered it is rejected rather than quietly
     * receipted to the individual.
     */
    public function test_the_payee_must_be_answered(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create(['payment_required' => true, 'payment_amount' => 900]);

        $this->actingAs($user)
            ->post("/trainings/{$training->id}/register", [])
            ->assertSessionHasErrors('charge_to');

        $this->assertSame(0, Registration::count());
    }

    /**
     * The certificate question is no longer asked, so the field stops arriving.
     * Everyone attending gets one, which is what the column has always
     * defaulted to — the absence must not read as "no certificate".
     */
    public function test_a_registration_without_the_certificate_field_still_asks_for_one(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create();

        $this->actingAs($user)
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertTrue(Registration::sole()->needs_certificate);
    }

    /** Staff and roster tooling still set it deliberately, so it stays accepted. */
    public function test_the_certificate_field_is_still_honoured_when_sent(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create();

        $this->actingAs($user)
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => false,
            ])
            ->assertSessionHas('success');

        $this->assertFalse(Registration::sole()->needs_certificate);
    }

    public function test_registration_records_who_the_fee_is_billed_to(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create();

        $this->actingAs($user)
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Agency->value,
                'needs_certificate' => false,
            ])
            ->assertSessionHas('success');

        $registration = Registration::sole();

        $this->assertSame(ChargeTo::Agency, $registration->charge_to);
        $this->assertFalse($registration->needs_certificate);
    }

    public function test_registration_requires_who_the_fee_is_billed_to(): void
    {
        $training = Training::factory()->create();

        $this->actingAs($this->participant())
            ->post("/trainings/{$training->id}/register", ['needs_certificate' => true])
            ->assertSessionHasErrors('charge_to');

        $this->assertSame(0, Registration::count());
    }

    // --- Supervisory course eligibility ------------------------------------

    private function supervisoryTraining(): Training
    {
        return Training::factory()->create(['is_supervisory' => true]);
    }

    private function participantAtGrade(string $grade): User
    {
        $user = $this->participant();
        $user->profile->forceFill(['salary_grade' => $grade])->save();

        return $user->fresh();
    }

    public function test_a_supervisory_course_turns_away_grades_below_the_floor(): void
    {
        $this->actingAs($this->participantAtGrade('SG 8'))
            ->post("/trainings/{$this->supervisoryTraining()->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertSessionHasErrors('registration');

        $this->assertSame(0, Registration::count());
    }

    public function test_a_supervisory_course_requires_proof_in_the_middle_band(): void
    {
        $this->actingAs($this->participantAtGrade('SG 16'))
            ->post("/trainings/{$this->supervisoryTraining()->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertSessionHasErrors('supporting_document');

        $this->assertSame(0, Registration::count());
    }

    public function test_proof_of_supervisory_function_is_stored_privately(): void
    {
        Storage::fake('local');

        $user = $this->participantAtGrade('SG 16');

        $this->actingAs($user)
            ->post("/trainings/{$this->supervisoryTraining()->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
                'supporting_document' => UploadedFile::fake()->create('designation.pdf', 64, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        $registration = Registration::sole();

        $this->assertNotNull($registration->supporting_document_path);
        Storage::disk('local')->assertExists($registration->supporting_document_path);

        // Never a public URL — the same rule as every other participant upload.
        $this->actingAs($user)
            ->get("/registrations/{$registration->id}/supporting-document")
            ->assertOk();
        $this->actingAs($this->participant())
            ->get("/registrations/{$registration->id}/supporting-document")
            ->assertForbidden();
    }

    public function test_a_supervisory_course_asks_nothing_extra_above_the_band(): void
    {
        $this->actingAs($this->participantAtGrade('SG 22'))
            ->post("/trainings/{$this->supervisoryTraining()->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, Registration::count());
    }

    /**
     * "Not Applicable" is a real choice for job-order staff. It must not read
     * as grade zero, which would put them below the floor and lock them out
     * with no way to see why.
     */
    public function test_an_unreadable_salary_grade_does_not_bar_a_participant(): void
    {
        $this->actingAs($this->participantAtGrade('Not Applicable'))
            ->post("/trainings/{$this->supervisoryTraining()->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, Registration::count());
    }

    public function test_an_ordinary_training_imposes_no_supervisory_rule(): void
    {
        $this->actingAs($this->participantAtGrade('SG 5'))
            ->post('/trainings/'.Training::factory()->create()->id.'/register', [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, Registration::count());
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

    /**
     * Where the slot gets confirmed depends on whether there is a fee.
     *
     * A free run has nothing to settle, so it is first come, first served and
     * the registration is approved on the spot. A charged run waits at pending
     * until an officer verifies the money — see PaymentTest for that half.
     */
    public function test_a_free_training_confirms_the_slot_immediately(): void
    {
        $registration = RegistrationService::register(
            $this->participant(),
            Training::factory()->create(['payment_required' => false])
        );

        $this->assertSame(RegistrationStatus::Approved, $registration->status);
    }

    public function test_a_paid_training_waits_for_the_fee(): void
    {
        $registration = RegistrationService::register(
            $this->participant(),
            Training::factory()->create(['payment_required' => true, 'payment_amount' => 1500])
        );

        $this->assertSame(RegistrationStatus::Pending, $registration->status);
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
        // Free run, so re-registering confirms the slot again rather than
        // rejoining a review queue that has nothing to review.
        $this->assertSame(RegistrationStatus::Approved, $again->status);
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
                // A free training confirms the slot at registration, so this
                // counts as registered rather than waiting on a review.
                ->where('summary.pending', 0)
                ->where('summary.registered', 1)
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

    // --- Learning & Development calendar (v1's calendar.php) --------------

    public function test_the_calendar_lays_the_month_out_as_whole_weeks(): void
    {
        $this->actingAs($this->participant())
            ->get('/trainings/calendar?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trainings/Calendar')
                ->where('month.label', 'March 2026')
                ->where('month.previous', '2026-02')
                ->where('month.next', '2026-04')
                // Always a rectangle: a grid that changes shape between months
                // reflows the page every time you page through it.
                ->where('weeks', fn ($weeks) => collect($weeks)
                    ->every(fn ($week) => count($week) === 7)
                )
            );
    }

    public function test_a_training_occupies_every_day_it_runs(): void
    {
        Training::factory()->create([
            'title' => 'Three Day Course',
            'status' => TrainingStatus::Published,
            'starts_at' => '2026-03-10 08:00:00',
            'ends_at' => '2026-03-12 17:00:00',
        ]);

        $this->actingAs($this->participant())
            ->get('/trainings/calendar?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('weeks', function ($weeks) {
                    $days = collect($weeks)->flatten(1)
                        ->filter(fn ($day) => filled($day['events']))
                        ->pluck('day')
                        ->values()
                        ->all();

                    // Drawing a calendar is pointless if a run only marks its
                    // first day.
                    return $days === [10, 11, 12];
                })
            );
    }

    public function test_a_training_spanning_a_month_boundary_shows_in_both_months(): void
    {
        Training::factory()->create([
            'title' => 'Boundary Course',
            'status' => TrainingStatus::Published,
            'starts_at' => '2026-09-30 08:00:00',
            'ends_at' => '2026-10-02 17:00:00',
        ]);

        // v1 matched on the start date alone, so this run vanished from the
        // October calendar — the month it was mostly running in.
        foreach (['2026-09', '2026-10'] as $month) {
            $this->actingAs($this->participant())
                ->get("/trainings/calendar?month={$month}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('trainings', 1)
                    ->where('trainings.0.title', 'Boundary Course')
                );
        }
    }

    public function test_an_unreadable_month_falls_back_to_this_one(): void
    {
        // The value comes off a query string people edit and share, so a
        // mistyped URL must not 500.
        $this->actingAs($this->participant())
            ->get('/trainings/calendar?month=not-a-month')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('month.is_current', true));
    }

    /**
     * Stand mid-month, so "a few days from now" is still the month on screen.
     *
     * The calendar shows one month and scopes its `trainings` list to it, which
     * is correct — but it means a fixture placed a few days out lands in *next*
     * month whenever the suite runs near the end of one, and the list comes back
     * empty. The two tests below then failed every 29th to 31st while proving
     * nothing was wrong, and the absence test below passed for the wrong reason
     * on exactly those days: an empty calendar is not evidence that a draft was
     * excluded from it.
     *
     * The 10th is arbitrary and only has to be far from both edges.
     */
    private function travelToMidMonth(): void
    {
        $this->travelTo(today()->startOfMonth()->addDays(9)->addHours(9));
    }

    public function test_the_calendar_marks_the_participants_own_registrations(): void
    {
        $this->travelToMidMonth();

        $participant = $this->participant();
        $training = Training::factory()->create([
            'status' => TrainingStatus::Published,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3),
        ]);

        RegistrationService::register($participant, $training);

        $this->actingAs($participant)
            ->get('/trainings/calendar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainings.0.is_registered', true)
                ->where('weeks', fn ($weeks) => collect($weeks)->flatten(1)
                    ->pluck('events')
                    ->flatten(1)
                    ->contains(fn ($event) => $event['is_registered'] === true)
                )
            );
    }

    public function test_an_unpublished_training_never_reaches_the_calendar(): void
    {
        // Without this the draft can fall outside the rendered month, and the
        // assertion below passes without ever exercising the visibility scope.
        $this->travelToMidMonth();

        Training::factory()->create([
            'title' => 'Draft Course',
            'status' => TrainingStatus::Draft,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2),
        ]);

        $this->actingAs($this->participant())
            ->get('/trainings/calendar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('trainings', 0));
    }

    /**
     * The .ics behind the dashboard's "Add to Calendar".
     *
     * Checks the fields a calendar client actually reads, and that the escaping
     * holds: a comma in a venue that reaches the file unescaped ends the
     * property early, and some clients then drop the event rather than complain.
     */
    public function test_a_registration_can_be_downloaded_as_a_calendar_file(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create([
            'title' => 'Records Management',
            'venue' => 'CSC Regional Office VIII, Palo, Leyte',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(8),
        ]);

        $registration = RegistrationService::register($user, $training);

        $response = $this->actingAs($user)
            ->get("/my/registrations/{$registration->id}/calendar")
            ->assertOk()
            ->assertHeader('content-type', 'text/calendar; charset=utf-8');

        $body = $response->getContent();

        $this->assertStringContainsString('BEGIN:VEVENT', $body);
        $this->assertStringContainsString('SUMMARY:Records Management', $body);
        // The venue's commas survive as escaped commas, not as field breaks.
        $this->assertStringContainsString('LOCATION:CSC Regional Office VIII\\, Palo\\, Leyte', $body);
        // Stable UID, so re-importing updates the entry rather than duplicating it.
        $this->assertStringContainsString("UID:registration-{$registration->id}@", $body);
        $this->assertStringContainsString('DTSTART:', $body);
        $this->assertStringContainsString('DTEND:', $body);
    }

    public function test_someone_elses_registration_cannot_be_downloaded_as_a_calendar_file(): void
    {
        $owner = $this->participant();
        $other = $this->participant();

        $registration = RegistrationService::register($owner, Training::factory()->create());

        $this->actingAs($other)
            ->get("/my/registrations/{$registration->id}/calendar")
            ->assertForbidden();
    }

    /**
     * The dashboard's counts link in here with ?status=, so the filter has to
     * narrow the list — otherwise a tile reading "1 Completed" lands on a page
     * showing everything and the reader counts again.
     */
    public function test_the_registrations_list_can_be_filtered_by_status(): void
    {
        $user = $this->participant();

        RegistrationService::register($user, Training::factory()->create(['title' => 'Still Open']));

        $completed = RegistrationService::register($user, Training::factory()->create(['title' => 'Finished']));
        $completed->forceFill(['status' => RegistrationStatus::Completed])->save();

        $this->actingAs($user)
            ->get('/my/registrations?status=completed')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('registrations', 1)
                ->where('registrations.0.training.title', 'Finished')
                ->where('filters.status', 'completed')
            );
    }

    /**
     * A hand-edited or stale status shows everything rather than nothing: an
     * empty page would read as "you have no registrations", which is a lie the
     * URL should not be able to tell.
     */
    public function test_an_unknown_status_filter_falls_back_to_showing_everything(): void
    {
        $user = $this->participant();

        RegistrationService::register($user, Training::factory()->create());

        $this->actingAs($user)
            ->get('/my/registrations?status=not-a-status')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('registrations', 1)
                ->where('filters.status', null)
            );
    }

    /** The chips only offer statuses the participant actually has. */
    public function test_filter_chips_cover_only_the_statuses_in_hand(): void
    {
        $user = $this->participant();

        $completed = RegistrationService::register($user, Training::factory()->create());
        $completed->forceFill(['status' => RegistrationStatus::Completed])->save();

        $this->actingAs($user)
            ->get('/my/registrations')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $options = $page->toArray()['props']['statusOptions'];

                $this->assertSame(['completed'], collect($options)->pluck('value')->all());
                $this->assertSame(1, $options[0]['count']);
            });
    }
}
