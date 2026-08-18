<?php

namespace Tests\Feature;

use App\Enums\ChargeTo;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admitting somebody who turned up at the venue without registering.
 *
 * The flow the office described, end to end: a participant who has an account
 * but no registration is scanned at the desk, enrolled, put on a promissory
 * note, and checked in — then the cashier takes their money later and the fee
 * clears.
 *
 * Most of these tests are about the guards a walk-in does *not* get past. The
 * feature relaxes the registration deadline and the capacity cap on purpose,
 * and the risk in a change shaped like that is that it quietly relaxes
 * something else too.
 */
class WalkInTest extends TestCase
{
    use RefreshDatabase;

    private function operator(Role $role = Role::Admin, ?FieldOffice $office = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
        ])->refresh();
    }

    private function participant(?FieldOffice $office = null): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);

        Profile::factory()->for($user)->create([
            'field_office_id' => $office?->getKey(),
        ]);

        $user->ensureQrToken();

        return $user->refresh();
    }

    private function training(array $attributes = []): Training
    {
        return Training::factory()->startingToday()->runningFor(1)->create([
            'accepts_walk_ins' => true,
            ...$attributes,
        ]);
    }

    private function admit(User $operator, Training $training, User $participant): TestResponse
    {
        return $this->actingAs($operator)->postJson(route('admin.scanner.walk-in'), [
            'training_id' => $training->getKey(),
            'token' => $participant->qr_token,
        ]);
    }

    public function test_a_walk_in_is_enrolled_noted_and_checked_in(): void
    {
        $training = $this->training([
            'payment_required' => true,
            'payment_amount' => 1500,
            'accepts_promissory' => true,
        ]);
        $participant = $this->participant();

        $response = $this->admit($this->operator(), $training, $participant);

        $response->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('checked_in', true)
            ->assertJsonPath('over_capacity', false);

        $registration = Registration::where('user_id', $participant->getKey())->firstOrFail();

        // Approved, not pending: the note settled the fee, which is what
        // confirmSlotOnSettlement does for any other settled payment.
        $this->assertSame(RegistrationStatus::Approved, $registration->status);
        $this->assertTrue($registration->is_walk_in);

        $payment = Payment::where('registration_id', $registration->getKey())->firstOrFail();
        $this->assertSame(PaymentMethod::Promissory, $payment->payment_method);
        $this->assertSame(PaymentStatus::Verified, $payment->status);

        $this->assertDatabaseHas('attendances', [
            'registration_id' => $registration->getKey(),
            'training_day' => 1,
        ]);

        // The note holds the slot but is not money, so nothing has cleared.
        $this->assertFalse($registration->fresh()->load('training', 'payments')->hasClearedFee());
    }

    public function test_the_cashier_clears_the_note_and_the_fee_is_settled(): void
    {
        $training = $this->training([
            'payment_required' => true,
            'payment_amount' => 1500,
            'accepts_promissory' => true,
        ]);
        $participant = $this->participant();
        $officer = $this->operator(Role::CollectingOfficer);

        $this->admit($this->operator(), $training, $participant)->assertOk();

        $registration = Registration::where('user_id', $participant->getKey())->firstOrFail();

        PaymentService::recordAtCounter($registration, $officer, [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
            'or_number' => 'OR-9001',
            'or_date' => now()->toDateString(),
        ]);

        $registration = $registration->fresh()->load('training', 'payments');

        $this->assertTrue($registration->hasClearedFee());

        // Two rows, not one edited in place: the promise and the payment are
        // both facts, and the trail has to show the gap between them.
        $this->assertSame(2, $registration->payments->count());
    }

    public function test_a_free_training_needs_no_note(): void
    {
        $training = $this->training(['payment_required' => false]);
        $participant = $this->participant();

        $this->admit($this->operator(), $training, $participant)
            ->assertOk()
            ->assertJsonPath('checked_in', true);

        $registration = Registration::where('user_id', $participant->getKey())->firstOrFail();

        $this->assertSame(RegistrationStatus::Approved, $registration->status);
        $this->assertSame(0, Payment::where('registration_id', $registration->getKey())->count());
    }

    public function test_a_paid_run_that_refuses_notes_enrols_but_does_not_check_in(): void
    {
        $training = $this->training([
            'payment_required' => true,
            'payment_amount' => 1500,
            'accepts_promissory' => false,
        ]);
        $participant = $this->participant();

        $this->admit($this->operator(), $training, $participant)
            ->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('checked_in', false);

        $registration = Registration::where('user_id', $participant->getKey())->firstOrFail();

        // Enrolled and waiting on the cashier — which is the point. Without the
        // registration there would be nothing for the cashier to take money
        // against, and the participant would be stuck outside permanently.
        $this->assertSame(RegistrationStatus::Pending, $registration->status);
        $this->assertSame(0, Attendance::where('registration_id', $registration->getKey())->count());
    }

    public function test_a_full_training_still_admits_and_reports_the_overrun(): void
    {
        $training = $this->training(['capacity' => 2, 'payment_required' => false]);

        Registration::factory()->approved()->count(2)->create([
            'training_id' => $training->getKey(),
        ]);

        $response = $this->admit($this->operator(), $training, $this->participant());

        $response->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('checked_in', true)
            ->assertJsonPath('over_capacity', true)
            ->assertJsonPath('over_by', 1);

        $this->assertSame(3, $training->fresh()->activeRegistrations()->count());
    }

    public function test_a_training_not_published_for_walk_ins_refuses(): void
    {
        $training = $this->training(['accepts_walk_ins' => false, 'payment_required' => false]);

        $this->admit($this->operator(), $training, $this->participant())
            ->assertStatus(422)
            ->assertJsonValidationErrors('walk_in');

        $this->assertSame(0, Registration::count());
    }

    public function test_a_training_not_running_today_refuses(): void
    {
        $training = Training::factory()->runningFor(1)->create([
            'accepts_walk_ins' => true,
            'payment_required' => false,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek(),
        ]);

        $this->admit($this->operator(), $training, $this->participant())
            ->assertStatus(422)
            ->assertJsonValidationErrors('walk_in');
    }

    public function test_a_field_office_cannot_admit_another_offices_participant(): void
    {
        $mine = FieldOffice::factory()->create();
        $theirs = FieldOffice::factory()->create();

        $training = $this->training(['payment_required' => false]);

        $this->admit(
            $this->operator(Role::FieldOffice, $mine),
            $training,
            $this->participant($theirs),
        )->assertStatus(404);

        $this->assertSame(0, Registration::count());
    }

    public function test_an_already_registered_participant_is_refused(): void
    {
        $training = $this->training(['payment_required' => false]);
        $participant = $this->participant();

        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->admit($this->operator(), $training, $participant)
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');

        $this->assertSame(1, Registration::count());
    }

    public function test_the_supervisory_bar_still_applies_to_a_walk_in(): void
    {
        $training = $this->training(['payment_required' => false, 'is_supervisory' => true]);

        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create(['salary_grade' => 'SG 8']);
        $participant->ensureQrToken();

        $this->admit($this->operator(), $training, $participant->refresh())
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');

        $this->assertSame(0, Registration::count());
    }

    public function test_management_cannot_admit_a_walk_in(): void
    {
        $training = $this->training(['payment_required' => false]);

        $this->admit($this->operator(Role::Management), $training, $this->participant())
            ->assertForbidden();
    }

    /**
     * The guard on the whole change.
     *
     * Walk-ins waive the deadline for a staff member standing at a desk. If
     * that waiver reached registrationHasClosed() it would reopen self-service
     * registration to the public on every run flagged for walk-ins, which is
     * emphatically not what was asked for.
     */
    public function test_self_service_registration_stays_closed_after_the_deadline(): void
    {
        $training = $this->training([
            'payment_required' => false,
            // Stated rather than inferred from the start date, which on a run
            // starting later today has not passed yet — the point of this test
            // is the closed window, so the window has to actually be closed.
            'registration_closes_at' => now()->subDay(),
        ]);
        $participant = $this->participant();

        $this->actingAs($participant)
            ->post(route('registrations.store', $training), [
                // Posted so the request clears field validation and actually
                // reaches the window check this test is about.
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertSessionHasErrors('registration');

        $this->assertSame(0, Registration::count());
    }
}
