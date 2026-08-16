<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RosterBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    private function registerOn(Training $training): Registration
    {
        return RegistrationService::register($this->participant(), $training);
    }

    private function bulk(Training $training, array $payload): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post("/admin/trainings/{$training->id}/registrations/bulk", $payload);
    }

    public function test_a_selection_can_be_approved_in_one_action(): void
    {
        $training = Training::factory()->paid()->create();
        $registrations = collect([$this->registerOn($training), $this->registerOn($training)]);

        $this->bulk($training, [
            'action' => 'approved',
            'ids' => $registrations->pluck('id')->all(),
        ])->assertRedirect();

        foreach ($registrations as $registration) {
            $this->assertSame(RegistrationStatus::Approved, $registration->refresh()->status);
        }
    }

    public function test_rows_that_are_no_longer_pending_are_skipped_and_reported(): void
    {
        $training = Training::factory()->paid()->create();
        $pending = $this->registerOn($training);
        $already = $this->registerOn($training);
        $already->forceFill(['status' => RegistrationStatus::Cancelled])->save();

        $this->bulk($training, [
            'action' => 'approved',
            'ids' => [$pending->id, $already->id],
        ])->assertSessionHas('success', fn (string $message) => str_contains($message, '1 skipped'));

        $this->assertSame(RegistrationStatus::Approved, $pending->refresh()->status);
        // A cancelled registration is not quietly revived by a bulk approve.
        $this->assertSame(RegistrationStatus::Cancelled, $already->refresh()->status);
    }

    public function test_completion_is_refused_without_the_attendance_to_back_it(): void
    {
        $training = Training::factory()->paid()->create();
        $registration = $this->registerOn($training);
        $registration->forceFill(['status' => RegistrationStatus::Approved])->save();

        // No attendance was ever recorded, so bulk must not manufacture a
        // completion — that override exists only on the per-row action, where it
        // carries a reason.
        $this->bulk($training, [
            'action' => 'completed',
            'ids' => [$registration->id],
        ])->assertSessionHas('success', fn (string $message) => str_contains($message, '1 skipped'));

        $this->assertSame(RegistrationStatus::Approved, $registration->refresh()->status);
    }

    public function test_a_selection_cannot_reach_across_trainings(): void
    {
        $training = Training::factory()->paid()->create();
        $other = Training::factory()->paid()->create();
        $mine = $this->registerOn($training);
        $theirs = $this->registerOn($other);

        $this->bulk($training, [
            'action' => 'approved',
            'ids' => [$mine->id, $theirs->id],
        ])->assertRedirect();

        $this->assertSame(RegistrationStatus::Approved, $mine->refresh()->status);
        $this->assertSame(RegistrationStatus::Pending, $theirs->refresh()->status);
    }

    public function test_a_bulk_rejection_must_carry_a_reason(): void
    {
        $training = Training::factory()->paid()->create();
        $registration = $this->registerOn($training);

        $this->bulk($training, [
            'action' => 'rejected',
            'ids' => [$registration->id],
        ])->assertSessionHasErrors('remarks');

        $this->assertSame(RegistrationStatus::Pending, $registration->refresh()->status);
    }

    public function test_field_office_staff_cannot_apply_bulk_decisions(): void
    {
        $training = Training::factory()->paid()->create();
        $registration = $this->registerOn($training);

        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($staff)
            ->post("/admin/trainings/{$training->id}/registrations/bulk", [
                'action' => 'approved',
                'ids' => [$registration->id],
            ])
            ->assertForbidden();

        $this->assertSame(RegistrationStatus::Pending, $registration->refresh()->status);
    }
}
