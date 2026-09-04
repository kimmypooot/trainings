<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\CancellationRequest;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\RegistrationOutput;
use App\Models\Training;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FieldOfficeScopingTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $leyte;

    private FieldOffice $samar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $this->samar = FieldOffice::where('code', 'sfo')->firstOrFail();
    }

    private function participantIn(FieldOffice $office, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office->id]);

        return $user->refresh();
    }

    private function fieldOfficeStaff(?FieldOffice $office): User
    {
        return User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $office?->id,
            'profile_completed_at' => now(),
        ]);
    }

    public function test_directory_shows_only_the_staff_members_own_office(): void
    {
        $mine = $this->participantIn($this->leyte, 'leyte@example.com');
        $this->participantIn($this->samar, 'samar@example.com');

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get('/admin/participants')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('participants.data', 1)
                ->where('participants.data.0.email', $mine->email)
                ->where('scopedTo', 'CSC Field Office - Leyte I')
            );
    }

    public function test_hrd_sees_every_office(): void
    {
        $this->participantIn($this->leyte, 'leyte@example.com');
        $this->participantIn($this->samar, 'samar@example.com');

        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]))
            ->get('/admin/participants')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('participants.data', 2)
                ->where('scopedTo', null)
            );
    }

    public function test_another_offices_participant_is_not_found(): void
    {
        $theirs = $this->participantIn($this->samar, 'samar@example.com');
        $mine = $this->participantIn($this->leyte, 'leyte@example.com');
        $staff = $this->fieldOfficeStaff($this->leyte);

        $this->actingAs($staff)->get("/admin/participants/{$theirs->id}")->assertNotFound();
        $this->actingAs($staff)->get("/admin/participants/{$mine->id}")->assertOk();
    }

    public function test_field_office_staff_may_manage_their_own_office_only(): void
    {
        $theirs = $this->participantIn($this->samar, 'samar@example.com');
        $mine = $this->participantIn($this->leyte, 'leyte@example.com');
        $staff = $this->fieldOfficeStaff($this->leyte);

        // A branch office correcting and switching off its own records is the
        // ordinary case, so the role check lets it through…
        $this->actingAs($staff)->get("/admin/participants/{$mine->id}/edit")->assertOk();
        $this->actingAs($staff)->post("/admin/participants/{$mine->id}/toggle")->assertRedirect();
        $this->assertFalse($mine->fresh()->is_active);

        // …and the office guard is what keeps "its own" honest, on every write
        // route and not just the listing.
        $this->actingAs($staff)->get("/admin/participants/{$theirs->id}/edit")->assertNotFound();
        $this->actingAs($staff)->put("/admin/participants/{$theirs->id}", [])->assertNotFound();
        $this->actingAs($staff)->post("/admin/participants/{$theirs->id}/toggle")->assertNotFound();
        $this->actingAs($staff)->post("/admin/participants/{$theirs->id}/password-reset")->assertNotFound();
        $this->assertTrue($theirs->fresh()->is_active);
    }

    public function test_unassigned_field_office_staff_may_manage_nobody(): void
    {
        $participant = $this->participantIn($this->leyte, 'leyte@example.com');
        $staff = $this->fieldOfficeStaff(null);

        // scopedFieldOfficeId() resolves to 0 for an unassigned account, which
        // matches nothing — failing closed on the writes as well as the reads.
        $this->actingAs($staff)->get("/admin/participants/{$participant->id}/edit")->assertNotFound();
        $this->actingAs($staff)->post("/admin/participants/{$participant->id}/toggle")->assertNotFound();
    }

    public function test_roster_is_filtered_but_the_training_stays_visible(): void
    {
        $training = Training::factory()->create();
        $mine = $this->participantIn($this->leyte, 'leyte@example.com');
        $theirs = $this->participantIn($this->samar, 'samar@example.com');

        RegistrationService::register($mine, $training);
        RegistrationService::register($theirs, $training);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('registrations', 1)
                ->where('registrations.0.email', $mine->email)
                ->where('summary.active', 1)
                ->where('scopedTo', 'CSC Field Office - Leyte I')
            );

        // HRD sees both.
        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]))
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registrations', 2));
    }

    public function test_the_trainings_list_counts_only_the_offices_own_people(): void
    {
        // A paying run, so the head count lands in the buckets that make up
        // Registered. On a free training all three are zero by definition and
        // the assertion would pass without proving anything.
        $training = Training::factory()->paid(1500)->create();

        RegistrationService::register($this->participantIn($this->leyte, 'leyte@example.com'), $training);
        RegistrationService::register($this->participantIn($this->samar, 'samar1@example.com'), $training);
        RegistrationService::register($this->participantIn($this->samar, 'samar2@example.com'), $training);

        /*
         * The run is regional, so it stays on the list for both readers — but
         * the head count is each reader's own. Told "3 registered" and then
         * shown a roster of one, a field office reads the difference as data
         * gone missing rather than as two questions with two answers.
         */
        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get('/admin/trainings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('trainings.data', 1)
                ->where('trainings.data.0.registered', 1)
                ->where('trainings.data.0.pending', 1)
                ->where('scopedTo', 'CSC Field Office - Leyte I')
            );

        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]))
            ->get('/admin/trainings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainings.data.0.registered', 3)
                ->where('trainings.data.0.pending', 3)
                // HRD is not scoped, and the page says so by saying nothing.
                ->where('scopedTo', null)
            );
    }

    public function test_the_trainings_list_counts_nobody_for_unassigned_staff(): void
    {
        $training = Training::factory()->paid(1500)->create();
        RegistrationService::register($this->participantIn($this->leyte, 'leyte@example.com'), $training);

        // scopedFieldOfficeId() resolves to 0 with no office assigned, which
        // matches nothing — the count fails closed rather than falling back to
        // the region.
        $this->actingAs($this->fieldOfficeStaff(null))
            ->get('/admin/trainings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('trainings.data', 1)
                ->where('trainings.data.0.registered', 0)
                ->where('trainings.data.0.pending', 0)
            );
    }

    public function test_the_roster_names_only_the_offices_own_collecting_officers(): void
    {
        $training = Training::factory()->create(['payment_required' => true, 'payment_amount' => 1500]);

        $mine = User::factory()->create([
            'name' => 'OURS',
            'role' => Role::FieldOffice,
            'field_office_id' => $this->leyte->id,
            'is_collecting_officer' => true,
            'profile_completed_at' => now(),
        ]);

        $theirs = User::factory()->create([
            'name' => 'THEIRS',
            'role' => Role::FieldOffice,
            'field_office_id' => $this->samar->id,
            'is_collecting_officer' => true,
            'profile_completed_at' => now(),
        ]);

        /*
         * Who took the money is a question about this office. An officer three
         * provinces away never handled that cash, and listing them turned the
         * dropdown into a directory of other offices' staff — the one control
         * on the roster that read across the whole region.
         */
        $this->actingAs($mine)
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('collectingOfficers', 1)
                ->where('collectingOfficers.0.label', 'OURS')
            );

        // HRD enters money on a field office's behalf, which is the case the
        // field exists for, so it still sees every officer.
        $labels = collect(
            $this->actingAs(User::factory()->create([
                'role' => Role::Admin,
                'profile_completed_at' => now(),
            ]))
                ->get("/admin/trainings/{$training->id}/roster")
                ->viewData('page')['props']['collectingOfficers']
        )->pluck('label');

        $this->assertTrue($labels->contains('OURS'));
        $this->assertTrue($labels->contains('THEIRS'));

        // And the officer named on the receipt is unaffected by any of this:
        // the designation grants the till, the office still bounds the reach.
        $this->assertTrue($theirs->collectsPayments());
    }

    public function test_dashboard_counts_are_scoped(): void
    {
        $training = Training::factory()->create();
        RegistrationService::register($this->participantIn($this->leyte, 'leyte@example.com'), $training);
        RegistrationService::register($this->participantIn($this->samar, 'samar@example.com'), $training);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.participants', 1)
                ->where('stats.registrations', 1)
                // The training catalogue is regional, so it is not scoped.
                ->where('stats.published', 1)
            );
    }

    public function test_staff_with_no_office_assigned_see_nothing(): void
    {
        $this->participantIn($this->leyte, 'leyte@example.com');

        // Fails closed: an unassigned field-office account must not fall back
        // to seeing every participant in the region.
        $this->actingAs($this->fieldOfficeStaff(null))
            ->get('/admin/participants')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('participants.data', 0));
    }

    public function test_participants_with_no_office_are_hidden_from_scoped_staff(): void
    {
        $user = User::factory()->create(['email' => 'orphan@example.com', 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => null]);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get('/admin/participants')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('participants.data', 0));
    }

    /**
     * A payment belonging to one office, so the money screens have something
     * to be scoped away from.
     */
    private function paymentIn(FieldOffice $office, string $email): Payment
    {
        $participant = $this->participantIn($office, $email);

        $training = Training::factory()->create([
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);

        $registration = Registration::factory()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'status' => RegistrationStatus::Pending,
        ]);

        return Payment::create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
            'payment_date' => now()->toDateString(),
        ]);
    }

    private function collectingOfficerIn(FieldOffice $office): User
    {
        return User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $office->id,
            'profile_completed_at' => now(),
            'is_collecting_officer' => true,
        ])->refresh();
    }

    /**
     * The verification queue is a list like any other.
     *
     * It reached this file late: the screen is gated on the collecting-officer
     * designation rather than a role, and a field office's own officer is a
     * field-office user who keeps their scoping while taking payments.
     */
    public function test_the_payment_queue_shows_only_the_officers_own_office(): void
    {
        $mine = $this->paymentIn($this->leyte, 'leyte@example.com');
        $this->paymentIn($this->samar, 'samar@example.com');

        $this->actingAs($this->collectingOfficerIn($this->leyte))
            ->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $mine->id)
                // The chips and tallies narrow with the rows. Scoped rows over
                // regional totals would describe work the officer cannot open.
                ->where('summary.pending.count', 1)
                ->where('summary.pending.amount', 1500)
            );
    }

    /**
     * Route model binding resolves by id alone, so the list hiding a payment
     * is not the same as the officer being unable to act on it.
     */
    public function test_an_officer_cannot_review_another_offices_payment(): void
    {
        $theirs = $this->paymentIn($this->samar, 'samar@example.com');

        $this->actingAs($this->collectingOfficerIn($this->leyte))
            ->post("/admin/payments/{$theirs->id}/review", [
                'decision' => 'verified',
                'or_number' => 'OR-4242',
                'or_date' => now()->toDateString(),
            ])
            ->assertNotFound();

        $this->assertSame(PaymentStatus::Pending, $theirs->fresh()->status);
    }

    /**
     * The proof route is reachable by any collecting officer, not just through
     * the scoped queue above — route model binding resolves by id alone, so an
     * officer who can never see another office's payment in a list must not be
     * able to open its proof image either by guessing the id in the URL.
     */
    public function test_an_officer_cannot_open_another_offices_payment_proof(): void
    {
        Storage::fake('local');

        $theirs = $this->paymentIn($this->samar, 'samar@example.com');
        $theirs->update([
            'proof_path' => UploadedFile::fake()->create('proof.pdf')->store('payment-proofs', 'local'),
        ]);

        $this->actingAs($this->collectingOfficerIn($this->leyte))
            ->get("/payments/{$theirs->id}/proof")
            ->assertNotFound();
    }

    /**
     * Same shape as the payment proof above, for the other participant upload
     * staff can be asked to open: a post-training output submitted for review.
     */
    public function test_an_officer_cannot_download_another_offices_registration_output(): void
    {
        Storage::fake('local');

        $participant = $this->participantIn($this->samar, 'samar@example.com');
        $training = Training::factory()->create();
        $registration = Registration::factory()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'status' => RegistrationStatus::Approved,
        ]);
        $output = RegistrationOutput::create([
            'registration_id' => $registration->getKey(),
            'title' => 'Action plan',
            'file_path' => UploadedFile::fake()->create('output.pdf')->store('outputs', 'local'),
            'original_filename' => 'output.pdf',
            'file_size' => 100,
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get("/outputs/{$output->id}/download")
            ->assertNotFound();
    }

    // ------------------------------------------------------- the review queues

    /*
     * The three decisions in Admin\RequestQueueController.
     *
     * `index()` scoped all three queues by office from the beginning; the three
     * POSTs that act on them scoped none, and nothing in this file covered them
     * — which is exactly why the hole survived. A field office could approve
     * another office's withdrawal, which frees a seat and starts a refund, by
     * posting its id.
     *
     * Each is asserted both ways: refused across offices, allowed within one.
     * The positive case is not decoration — a scope that refuses everything
     * would satisfy the negative assertion on its own.
     */

    private function registrationFor(User $participant): Registration
    {
        return Registration::factory()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create()->getKey(),
            'status' => RegistrationStatus::Approved,
        ]);
    }

    private function cancellationFor(FieldOffice $office, string $email): CancellationRequest
    {
        return CancellationRequest::create([
            'registration_id' => $this->registrationFor($this->participantIn($office, $email))->getKey(),
            'reason' => 'Reassigned that week.',
            'status' => RequestStatus::Pending,
        ]);
    }

    public function test_an_officer_cannot_review_another_offices_cancellation(): void
    {
        $cancellation = $this->cancellationFor($this->samar, 'samar.cancel@example.com');

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->post("/admin/requests/cancellations/{$cancellation->id}", ['decision' => 'approved'])
            ->assertNotFound();

        $this->assertSame(
            RequestStatus::Pending,
            $cancellation->fresh()->status,
            'Another office’s withdrawal was decided — that frees a seat and starts a refund.'
        );
    }

    public function test_an_officer_can_review_their_own_offices_cancellation(): void
    {
        $cancellation = $this->cancellationFor($this->leyte, 'leyte.cancel@example.com');

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->post("/admin/requests/cancellations/{$cancellation->id}", ['decision' => 'approved'])
            ->assertRedirect();

        $this->assertSame(RequestStatus::Approved, $cancellation->fresh()->status);
    }

    public function test_an_officer_cannot_review_another_offices_output(): void
    {
        Storage::fake('local');

        $registration = $this->registrationFor($this->participantIn($this->samar, 'samar.output@example.com'));

        $output = RegistrationOutput::create([
            'registration_id' => $registration->getKey(),
            'title' => 'Action plan',
            'file_path' => UploadedFile::fake()->create('output.pdf')->store('outputs', 'local'),
            'original_filename' => 'output.pdf',
            'file_size' => 100,
            'mime_type' => 'application/pdf',
            'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->post("/admin/requests/outputs/{$output->id}", ['decision' => 'approved'])
            ->assertNotFound();

        $this->assertSame(RequestStatus::Pending, $output->fresh()->status);
    }

    public function test_an_officer_cannot_review_another_offices_training_request(): void
    {
        $requester = $this->participantIn($this->samar, 'samar.request@example.com');

        $trainingRequest = TrainingRequest::create([
            'requested_by' => $requester->getKey(),
            'title' => 'Records Management',
            'justification' => 'The unit has no trained records officer.',
            'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->post("/admin/requests/trainings/{$trainingRequest->id}", ['decision' => 'approved'])
            ->assertNotFound();

        $this->assertSame(RequestStatus::Pending, $trainingRequest->fresh()->status);
    }

    /**
     * HRD is not office-scoped, so the same posts must keep working for them —
     * the fix must narrow the field office, not the queue.
     */
    public function test_hrd_can_still_review_any_offices_cancellation(): void
    {
        $cancellation = $this->cancellationFor($this->samar, 'samar.hrd@example.com');

        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]))
            ->post("/admin/requests/cancellations/{$cancellation->id}", ['decision' => 'approved'])
            ->assertRedirect();

        $this->assertSame(RequestStatus::Approved, $cancellation->fresh()->status);
    }

    // ------------------------------------------------- the scanned badge door

    /*
     * GET /scan/{token} and POST /scan/{token}/check-in.
     *
     * These sat in the participant route group behind nothing but an isStaff()
     * check, which was coarser than every other attendance route — so
     * `management`, named out of the attendance group precisely because it
     * "records nothing", could record attendance here, and a field office could
     * read and check in another office's participant.
     */

    private function badgeToken(User $participant): string
    {
        return $participant->ensureQrToken();
    }

    public function test_management_cannot_open_a_scanned_badge(): void
    {
        $token = $this->badgeToken($this->participantIn($this->leyte, 'badge.mgmt@example.com'));

        $this->actingAs(User::factory()->create(['role' => Role::Management, 'profile_completed_at' => now()]))
            ->get("/scan/{$token}")
            ->assertForbidden();
    }

    public function test_management_cannot_check_someone_in_through_a_scanned_badge(): void
    {
        $participant = $this->participantIn($this->leyte, 'badge.mgmt2@example.com');
        $registration = $this->registrationFor($participant);

        $this->actingAs(User::factory()->create(['role' => Role::Management, 'profile_completed_at' => now()]))
            ->post("/scan/{$this->badgeToken($participant)}/check-in", [
                'registration_id' => $registration->getKey(),
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            $registration->attendances()->count(),
            'An oversight role recorded attendance through the badge door.'
        );
    }

    public function test_an_officer_cannot_scan_another_offices_participant(): void
    {
        $token = $this->badgeToken($this->participantIn($this->samar, 'samar.badge@example.com'));

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get("/scan/{$token}")
            ->assertNotFound();
    }

    public function test_an_officer_can_scan_their_own_offices_participant(): void
    {
        $token = $this->badgeToken($this->participantIn($this->leyte, 'leyte.badge@example.com'));

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get("/scan/{$token}")
            ->assertSuccessful();
    }
}
