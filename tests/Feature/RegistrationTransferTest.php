<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\RegistrationTransferred;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Moving a roster selection to another run, ported from v1's
 * `transfer-participants.php`.
 */
class RegistrationTransferTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role = Role::Admin, ?FieldOffice $office = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
        ]);
    }

    private function participant(?FieldOffice $office = null): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office?->getKey()]);

        return $user->refresh();
    }

    private function registration(Training $training, ?User $user = null, RegistrationStatus $status = RegistrationStatus::Approved): Registration
    {
        return Registration::factory()->create([
            'user_id' => ($user ?? $this->participant())->getKey(),
            'training_id' => $training->getKey(),
            'status' => $status,
        ]);
    }

    public function test_a_selection_moves_to_the_target_training(): void
    {
        $source = Training::factory()->create();
        $target = Training::factory()->create();
        $a = $this->registration($source);
        $b = $this->registration($source);

        $result = RegistrationService::transfer(
            [$a->id, $b->id],
            $target,
            $this->staff(),
            'The venue became unavailable.',
        );

        $this->assertSame(2, $result['moved']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame($target->getKey(), $a->fresh()->training_id);
        $this->assertSame($target->getKey(), $b->fresh()->training_id);
    }

    /**
     * The point of a transfer over cancel-and-re-register is that the history
     * survives: registration date, attendance, and any payment.
     */
    public function test_history_travels_with_the_registration(): void
    {
        $source = Training::factory()->startingToday()->create();
        $target = Training::factory()->create();
        $registration = $this->registration($source);

        $registeredAt = $registration->registered_at;
        Attendance::factory()->create(['registration_id' => $registration->getKey()]);
        $payment = Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $registration->user_id,
            'training_id' => $source->getKey(),
        ]);

        RegistrationService::transfer([$registration->id], $target, $this->staff(), 'Rescheduled.');

        $registration = $registration->fresh();

        $this->assertSame($registeredAt->toDateTimeString(), $registration->registered_at->toDateTimeString());
        $this->assertSame(1, $registration->attendances()->count());
        // Payments belong to the registration, so they follow it.
        $this->assertSame($target->getKey(), $payment->fresh()->training_id);
    }

    public function test_a_participant_already_on_the_target_is_skipped(): void
    {
        $source = Training::factory()->create();
        $target = Training::factory()->create();
        $participant = $this->participant();

        $registration = $this->registration($source, $participant);
        $this->registration($target, $participant);

        $result = RegistrationService::transfer([$registration->id], $target, $this->staff(), 'Rescheduled.');

        $this->assertSame(0, $result['moved']);
        $this->assertStringContainsString('already registered for the target', $result['skipped'][0]);
        $this->assertSame($source->getKey(), $registration->fresh()->training_id);
    }

    /**
     * A batch larger than the room left moves whoever fits rather than
     * refusing everyone — the usual intent is to salvage what can be salvaged.
     */
    public function test_a_full_target_takes_whoever_fits(): void
    {
        $source = Training::factory()->create();
        $target = Training::factory()->create(['capacity' => 1]);

        $a = $this->registration($source);
        $b = $this->registration($source);

        $result = RegistrationService::transfer([$a->id, $b->id], $target, $this->staff(), 'Rescheduled.');

        $this->assertSame(1, $result['moved']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('target is full', $result['skipped'][0]);
    }

    public function test_a_cancelled_registration_is_not_moved(): void
    {
        $source = Training::factory()->create();
        $target = Training::factory()->create();
        $registration = $this->registration($source, null, RegistrationStatus::Cancelled);

        $result = RegistrationService::transfer([$registration->id], $target, $this->staff(), 'Rescheduled.');

        $this->assertSame(0, $result['moved']);
        $this->assertSame($source->getKey(), $registration->fresh()->training_id);
    }

    public function test_a_closed_training_cannot_receive_transfers(): void
    {
        $source = Training::factory()->create();
        $registration = $this->registration($source);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('open');

        RegistrationService::transfer(
            [$registration->id],
            Training::factory()->draft()->create(),
            $this->staff(),
            'Rescheduled.',
        );
    }

    /**
     * Same rule as the roster itself: a selection posted from a page must not
     * become a way to act on a registration the staff member cannot see.
     */
    public function test_office_scoping_applies_to_the_selection(): void
    {
        $officeA = FieldOffice::factory()->create();
        $officeB = FieldOffice::factory()->create();
        $source = Training::factory()->create();
        $target = Training::factory()->create();

        $mine = $this->registration($source, $this->participant($officeA));
        $theirs = $this->registration($source, $this->participant($officeB));

        $result = RegistrationService::transfer(
            [$mine->id, $theirs->id],
            $target,
            $this->staff(Role::FieldOffice, $officeA),
            'Rescheduled.',
            $officeA->getKey(),
        );

        $this->assertSame(1, $result['moved']);
        $this->assertSame($target->getKey(), $mine->fresh()->training_id);
        $this->assertSame($source->getKey(), $theirs->fresh()->training_id);
    }

    public function test_the_move_is_recorded_with_the_fee_difference(): void
    {
        $source = Training::factory()->create(['payment_required' => true, 'payment_amount' => 1000]);
        $target = Training::factory()->create(['payment_required' => true, 'payment_amount' => 1500]);
        $registration = $this->registration($source);

        RegistrationService::transfer([$registration->id], $target, $this->staff(), 'Rescheduled.');

        $log = ActivityLog::where('action', 'registration.transferred')->sole();

        $this->assertSame($source->getKey(), $log->properties['from_training_id']);
        $this->assertSame($target->getKey(), $log->properties['to_training_id']);
        // Finance reconciles against this rather than discovering it later.
        $this->assertSame(500.0, $log->properties['fee_difference']);
    }

    public function test_the_participant_is_told_the_schedule_changed(): void
    {
        Notification::fake();

        $source = Training::factory()->create();
        $target = Training::factory()->create();
        $participant = $this->participant();
        $registration = $this->registration($source, $participant);

        RegistrationService::transfer([$registration->id], $target, $this->staff(), 'The venue flooded.');

        Notification::assertSentTo($participant, RegistrationTransferred::class);
    }

    // --- Endpoint ----------------------------------------------------------

    public function test_staff_can_transfer_from_the_roster(): void
    {
        $source = Training::factory()->create();
        $target = Training::factory()->create();
        $registration = $this->registration($source);

        $this->actingAs($this->staff())
            ->post("/admin/trainings/{$source->id}/registrations/transfer", [
                'target_training_id' => $target->id,
                'ids' => [$registration->id],
                'reason' => 'The venue became unavailable.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($target->getKey(), $registration->fresh()->training_id);
    }

    public function test_transferring_onto_the_same_training_is_refused(): void
    {
        $training = Training::factory()->create();
        $registration = $this->registration($training);

        $this->actingAs($this->staff())
            ->from("/admin/trainings/{$training->id}/roster")
            ->post("/admin/trainings/{$training->id}/registrations/transfer", [
                'target_training_id' => $training->id,
                'ids' => [$registration->id],
                'reason' => 'A reason that is long enough.',
            ])
            ->assertSessionHasErrors('target_training_id');
    }

    /**
     * "Moved 12" when three were skipped is how a participant quietly stays on
     * a training that no longer runs.
     */
    public function test_skipped_participants_are_reported_back(): void
    {
        $source = Training::factory()->create();
        $target = Training::factory()->create(['capacity' => 1]);
        $a = $this->registration($source);
        $b = $this->registration($source);

        $this->actingAs($this->staff())
            ->post("/admin/trainings/{$source->id}/registrations/transfer", [
                'target_training_id' => $target->id,
                'ids' => [$a->id, $b->id],
                'reason' => 'The venue became unavailable.',
            ])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Skipped'));
    }

    public function test_a_transfer_that_moves_nobody_reports_an_error(): void
    {
        $source = Training::factory()->create();
        $target = Training::factory()->create();
        $registration = $this->registration($source, null, RegistrationStatus::Cancelled);

        $this->actingAs($this->staff())
            ->from("/admin/trainings/{$source->id}/roster")
            ->post("/admin/trainings/{$source->id}/registrations/transfer", [
                'target_training_id' => $target->id,
                'ids' => [$registration->id],
                'reason' => 'The venue became unavailable.',
            ])
            ->assertSessionHasErrors('transfer');
    }

    public function test_transfers_are_closed_to_roles_that_cannot_create_trainings(): void
    {
        $source = Training::factory()->create();
        $target = Training::factory()->create();
        $registration = $this->registration($source);

        foreach ([Role::FieldOffice, Role::Management] as $role) {
            $this->actingAs($this->staff($role))
                ->post("/admin/trainings/{$source->id}/registrations/transfer", [
                    'target_training_id' => $target->id,
                    'ids' => [$registration->id],
                    'reason' => 'The venue became unavailable.',
                ])
                ->assertForbidden();
        }
    }
}
