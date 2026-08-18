<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
