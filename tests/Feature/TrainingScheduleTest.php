<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Models\Profile;
use App\Models\Training;
use App\Models\User;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The enriched training schema ported from v1: codes, delivery mode, the
 * registration window, and the day numbering that per-day attendance rests on.
 */
class TrainingScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Records Management Seminar',
            'venue' => 'CSC Regional Office VIII',
            'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addWeek()->addHours(8)->format('Y-m-d\TH:i'),
            'status' => TrainingStatus::Published->value,
            ...$overrides,
        ];
    }

    public function test_a_training_code_is_generated_when_left_blank(): void
    {
        $this->actingAs($this->staff())
            ->post('/admin/trainings', $this->payload())
            ->assertRedirect('/admin/trainings');

        $this->assertSame(
            sprintf('TRN-%s-0001', now()->addWeek()->format('Y')),
            Training::first()->training_code
        );
    }

    public function test_a_supplied_training_code_is_kept_and_must_be_unique(): void
    {
        Training::factory()->create(['training_code' => 'TRN-CUSTOM-1']);

        $this->actingAs($this->staff())
            ->from('/admin/trainings/create')
            ->post('/admin/trainings', $this->payload(['training_code' => 'TRN-CUSTOM-1']))
            ->assertSessionHasErrors('training_code');

        $this->actingAs($this->staff())
            ->post('/admin/trainings', $this->payload(['training_code' => 'TRN-CUSTOM-2']))
            ->assertRedirect('/admin/trainings');

        $this->assertSame('TRN-CUSTOM-2', Training::where('title', 'Records Management Seminar')->first()->training_code);
    }

    public function test_editing_a_training_keeps_its_existing_code(): void
    {
        $training = Training::factory()->create(['training_code' => 'TRN-KEEP-ME']);

        $this->actingAs($this->staff())
            ->put("/admin/trainings/{$training->id}", $this->payload([
                'title' => 'Renamed',
                'training_code' => '',
            ]))
            ->assertRedirect('/admin/trainings');

        $this->assertSame('TRN-KEEP-ME', $training->fresh()->training_code);
    }

    public function test_duration_is_derived_from_the_dates_when_left_blank(): void
    {
        $starts = now()->addWeek()->startOfDay()->addHours(8);

        $this->actingAs($this->staff())
            ->post('/admin/trainings', $this->payload([
                'starts_at' => $starts->format('Y-m-d\TH:i'),
                'ends_at' => $starts->copy()->addDays(2)->addHours(9)->format('Y-m-d\TH:i'),
            ]))
            ->assertRedirect('/admin/trainings');

        // Three calendar days, counting both endpoints.
        $this->assertSame(3, Training::first()->duration_days);
    }

    public function test_an_explicit_duration_overrides_the_derived_one(): void
    {
        $this->actingAs($this->staff())
            ->post('/admin/trainings', $this->payload(['duration_days' => 5]))
            ->assertRedirect('/admin/trainings');

        $this->assertSame(5, Training::first()->duration_days);
    }

    public function test_mode_defaults_to_face_to_face(): void
    {
        $this->actingAs($this->staff())->post('/admin/trainings', $this->payload());

        $this->assertSame(TrainingMode::FaceToFace, Training::first()->mode);
    }

    public function test_a_paid_training_must_carry_an_amount(): void
    {
        $this->actingAs($this->staff())
            ->from('/admin/trainings/create')
            ->post('/admin/trainings', $this->payload(['payment_required' => true]))
            ->assertSessionHasErrors('payment_amount');

        $this->actingAs($this->staff())
            ->post('/admin/trainings', $this->payload([
                'payment_required' => true,
                'payment_amount' => 1500,
            ]))
            ->assertRedirect('/admin/trainings');

        $this->assertSame('1500.00', Training::first()->payment_amount);
    }

    public function test_training_days_are_numbered_from_one(): void
    {
        $training = Training::factory()->create([
            'starts_at' => now()->addWeek()->startOfDay()->addHours(8),
            'duration_days' => 3,
        ]);

        $days = $training->trainingDays();

        $this->assertCount(3, $days);
        $this->assertSame([1, 2, 3], array_column($days, 'day'));
        $this->assertTrue($days[2]['date']->isSameDay($training->starts_at->copy()->addDays(2)));
    }

    public function test_a_date_outside_the_run_has_no_day_number(): void
    {
        $training = Training::factory()->create([
            'starts_at' => now()->addWeek()->startOfDay()->addHours(8),
            'duration_days' => 2,
        ]);

        $this->assertSame(1, $training->dayNumberFor($training->starts_at));
        $this->assertSame(2, $training->dayNumberFor($training->starts_at->copy()->addDay()));
        $this->assertNull($training->dayNumberFor($training->starts_at->copy()->addDays(2)));
        $this->assertNull($training->dayNumberFor($training->starts_at->copy()->subDay()));
    }

    public function test_registration_is_refused_before_the_window_opens(): void
    {
        $training = Training::factory()->create([
            'registration_opens_at' => now()->addDays(3),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has not opened yet');

        RegistrationService::register($this->participant(), $training);
    }

    public function test_registration_is_allowed_once_the_window_has_opened(): void
    {
        $training = Training::factory()->create([
            'registration_opens_at' => now()->subDay(),
        ]);

        $this->assertNotNull(RegistrationService::register($this->participant(), $training));
    }
}
