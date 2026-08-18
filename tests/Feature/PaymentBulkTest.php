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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing a batch of promissory notes.
 *
 * A walk-in event leaves the collecting officer with as many notes as it
 * admitted people, and verifying them one dialog at a time is the queue this
 * endpoint removes. The tests are mostly about what it refuses to include in a
 * batch: verifying real money issues an official receipt, and a batch has no
 * way to give each payment its own.
 */
class PaymentBulkTest extends TestCase
{
    use RefreshDatabase;

    private function officer(?FieldOffice $office = null): User
    {
        return User::factory()->create([
            'role' => $office ? Role::FieldOffice : Role::CollectingOfficer,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
            'is_collecting_officer' => true,
        ])->refresh();
    }

    private function training(): Training
    {
        return Training::factory()->create([
            'payment_required' => true,
            'payment_amount' => 1500,
            'accepts_promissory' => true,
        ]);
    }

    private function note(
        Training $training,
        ?FieldOffice $office = null,
        PaymentMethod $method = PaymentMethod::Promissory
    ): Payment {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create(['field_office_id' => $office?->getKey()]);

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
            'payment_method' => $method,
            'payment_date' => now()->toDateString(),
        ]);
    }

    public function test_a_batch_of_notes_is_verified_and_the_slots_confirmed(): void
    {
        $training = $this->training();
        $notes = collect(range(1, 3))->map(fn () => $this->note($training));

        $this->actingAs($this->officer())
            ->post(route('admin.payments.bulk'), ['ids' => $notes->pluck('id')->all()])
            ->assertRedirect();

        foreach ($notes as $note) {
            $this->assertSame(PaymentStatus::Verified, $note->fresh()->status);

            // The point of settling a note: the registration is confirmed
            // without anybody reviewing it a second time.
            $this->assertSame(
                RegistrationStatus::Approved,
                $note->fresh()->registration->status,
            );
        }
    }

    public function test_real_money_is_skipped_rather_than_verified_without_a_receipt(): void
    {
        $training = $this->training();
        $note = $this->note($training);
        $cash = $this->note($training, method: PaymentMethod::Cash);

        $this->actingAs($this->officer())
            ->post(route('admin.payments.bulk'), ['ids' => [$note->id, $cash->id]])
            ->assertRedirect();

        $this->assertSame(PaymentStatus::Verified, $note->fresh()->status);

        // Untouched, and with no OR number invented for it.
        $this->assertSame(PaymentStatus::Pending, $cash->fresh()->status);
        $this->assertNull($cash->fresh()->or_number);
    }

    public function test_an_already_verified_note_is_skipped(): void
    {
        $training = $this->training();
        $note = $this->note($training);
        $note->forceFill(['status' => PaymentStatus::Verified])->save();

        $this->actingAs($this->officer())
            ->post(route('admin.payments.bulk'), ['ids' => [$note->id]])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '0 promissory note(s) verified')
                && str_contains($message, '1 skipped'));
    }

    public function test_a_field_office_officer_cannot_clear_another_offices_notes(): void
    {
        $mine = FieldOffice::factory()->create();
        $theirs = FieldOffice::factory()->create();

        $training = $this->training();
        $ours = $this->note($training, $mine);
        $theirNote = $this->note($training, $theirs);

        $this->actingAs($this->officer($mine))
            ->post(route('admin.payments.bulk'), ['ids' => [$ours->id, $theirNote->id]])
            ->assertRedirect();

        $this->assertSame(PaymentStatus::Verified, $ours->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $theirNote->fresh()->status);
    }

    /**
     * An id that resolves to nothing is reported, not swallowed.
     *
     * Out-of-scope rows drop out of the query silently, and a total that
     * counted only what it touched would let an office-scope mismatch read as
     * a clean run.
     */
    public function test_unresolvable_ids_are_counted_as_skipped(): void
    {
        $note = $this->note($this->training());

        $this->actingAs($this->officer())
            ->post(route('admin.payments.bulk'), ['ids' => [$note->id, 999_999]])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '1 promissory note(s) verified')
                && str_contains($message, '1 skipped'));
    }

    public function test_a_staff_member_who_does_not_collect_payments_is_refused(): void
    {
        $note = $this->note($this->training());

        // Field office rather than admin: admin is in Role::financial() and
        // therefore collects by virtue of the role, so it would prove nothing.
        $clerk = User::factory()->create([
            'role' => Role::FieldOffice,
            'profile_completed_at' => now(),
            'is_collecting_officer' => false,
        ])->refresh();

        $this->actingAs($clerk)
            ->post(route('admin.payments.bulk'), ['ids' => [$note->id]])
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, $note->fresh()->status);
    }
}
