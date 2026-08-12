<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The QR check-in chain: a scan resolves the participant, records the day, and
 * feeds completion.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    /**
     * A participant approved for a training that is running today.
     *
     * @return array{0: User, 1: Training, 2: Registration}
     */
    private function scenario(int $days = 1): array
    {
        $participant = $this->participant();
        $training = Training::factory()->startingToday()->runningFor($days)->create();
        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        return [$participant, $training, $registration];
    }

    public function test_a_scan_records_attendance_for_todays_training(): void
    {
        [$participant, $training, $registration] = $this->scenario();
        $token = $participant->ensureQrToken();

        $this->actingAs($this->staff())
            ->post("/scan/{$token}/check-in", ['registration_id' => $registration->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $attendance = Attendance::sole();

        $this->assertSame($registration->id, $attendance->registration_id);
        $this->assertSame(1, $attendance->training_day);
        $this->assertNotNull($attendance->time_in);
        $this->assertTrue($attendance->attendance_date->isToday());
        $this->assertSame($training->id, $registration->fresh()->training_id);
    }

    public function test_scanning_twice_does_not_duplicate_or_move_the_arrival_time(): void
    {
        [$participant, , $registration] = $this->scenario();
        $token = $participant->ensureQrToken();
        $staff = $this->staff();

        $this->actingAs($staff)->post("/scan/{$token}/check-in", ['registration_id' => $registration->id]);
        $first = Attendance::sole()->time_in;

        $this->travel(2)->hours();

        $this->actingAs($staff)->post("/scan/{$token}/check-in", ['registration_id' => $registration->id]);

        $this->assertSame(1, Attendance::count());
        $this->assertSame($first, Attendance::sole()->time_in);
    }

    public function test_the_scan_page_lists_only_trainings_running_today(): void
    {
        [$participant, , $registration] = $this->scenario();

        // A second approved registration, but for a training next month.
        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create(['starts_at' => now()->addMonth()])->getKey(),
        ]);

        $this->actingAs($this->staff())
            ->get("/scan/{$participant->ensureQrToken()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/ScanResult')
                ->has('sessions', 1)
                ->where('sessions.0.registration_id', $registration->id)
                ->where('sessions.0.day', 1)
                ->where('sessions.0.already_checked_in', false)
            );
    }

    public function test_a_participant_cannot_check_anyone_in(): void
    {
        [$participant, , $registration] = $this->scenario();

        $this->actingAs($this->participant())
            ->post("/scan/{$participant->ensureQrToken()}/check-in", ['registration_id' => $registration->id])
            ->assertForbidden();

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_registration_belonging_to_someone_else_is_refused(): void
    {
        [$participant] = $this->scenario();
        [, , $othersRegistration] = $this->scenario();

        $this->actingAs($this->staff())
            ->post("/scan/{$participant->ensureQrToken()}/check-in", [
                'registration_id' => $othersRegistration->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, Attendance::count());
    }

    public function test_attendance_is_refused_on_a_day_the_training_does_not_run(): void
    {
        $participant = $this->participant();
        $training = Training::factory()->create(['starts_at' => now()->addMonth()]);
        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is not running on');

        AttendanceService::checkIn($registration, $this->staff());
    }

    public function test_a_cancelled_registration_cannot_be_marked(): void
    {
        [, , $registration] = $this->scenario();
        $registration->update(['status' => RegistrationStatus::Cancelled]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('only an approved participant');

        AttendanceService::checkIn($registration, $this->staff());
    }

    public function test_arriving_within_the_grace_period_is_present_and_later_is_late(): void
    {
        [, $training, $registration] = $this->scenario();
        $start = CarbonImmutable::parse($training->starts_at);

        $this->assertSame(
            AttendanceStatus::Present,
            AttendanceService::checkIn($registration, $this->staff(), $start->addMinutes(10))->status
        );

        Attendance::query()->delete();

        $this->assertSame(
            AttendanceStatus::Late,
            AttendanceService::checkIn($registration, $this->staff(), $start->addHours(2))->status
        );
    }

    public function test_each_day_of_a_multi_day_training_is_recorded_separately(): void
    {
        [, $training, $registration] = $this->scenario(days: 3);
        $staff = $this->staff();
        $start = CarbonImmutable::parse($training->starts_at);

        AttendanceService::checkIn($registration, $staff, $start);
        AttendanceService::checkIn($registration, $staff, $start->addDay());
        AttendanceService::checkIn($registration, $staff, $start->addDays(2));

        $this->assertSame([1, 2, 3], Attendance::orderBy('training_day')->pluck('training_day')->all());
        $this->assertSame(3, $registration->fresh()->creditedDays());
    }

    public function test_staff_can_mark_an_excused_absence_from_the_roster(): void
    {
        [, , $registration] = $this->scenario(days: 2);

        $this->actingAs($this->staff())
            ->post("/admin/registrations/{$registration->id}/attendance", [
                'training_day' => 2,
                'status' => AttendanceStatus::Excused->value,
                'remarks' => 'Bereavement leave.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $attendance = Attendance::sole();

        $this->assertSame(AttendanceStatus::Excused, $attendance->status);
        $this->assertSame('Bereavement leave.', $attendance->remarks);
    }

    public function test_marking_absent_clears_a_mis_scanned_arrival(): void
    {
        [, , $registration] = $this->scenario();
        $staff = $this->staff();

        AttendanceService::checkIn($registration, $staff);
        $this->assertNotNull(Attendance::sole()->time_in);

        AttendanceService::mark($registration, 1, AttendanceStatus::Absent, $staff, 'Scanned by mistake.');

        $this->assertNull(Attendance::sole()->time_in);
        $this->assertNull($registration->fresh()->attended_at);
    }

    public function test_a_day_outside_the_run_cannot_be_marked(): void
    {
        [, , $registration] = $this->scenario(days: 2);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has no day 3');

        AttendanceService::mark($registration, 3, AttendanceStatus::Present, $this->staff());
    }

    public function test_check_in_stamps_the_denormalised_attended_at(): void
    {
        [, , $registration] = $this->scenario();

        $this->assertNull($registration->attended_at);

        AttendanceService::checkIn($registration, $this->staff());

        $this->assertTrue($registration->fresh()->attended_at->isToday());
    }

    public function test_completion_is_refused_without_enough_attendance(): void
    {
        [, , $registration] = $this->scenario(days: 4);

        $this->actingAs($this->staff())
            ->from('/admin/trainings')
            ->post("/admin/registrations/{$registration->id}/complete")
            ->assertSessionHasErrors('registration');

        $this->assertSame(RegistrationStatus::Approved, $registration->fresh()->status);
    }

    public function test_completion_succeeds_once_the_majority_of_days_are_recorded(): void
    {
        [, $training, $registration] = $this->scenario(days: 4);
        $staff = $this->staff();
        $start = CarbonImmutable::parse($training->starts_at);

        AttendanceService::checkIn($registration, $staff, $start);
        AttendanceService::checkIn($registration, $staff, $start->addDay());

        $this->actingAs($staff)
            ->post("/admin/registrations/{$registration->id}/complete")
            ->assertSessionHas('success');

        $this->assertSame(RegistrationStatus::Completed, $registration->fresh()->status);
    }

    public function test_forcing_completion_requires_a_reason(): void
    {
        [, , $registration] = $this->scenario(days: 4);
        $staff = $this->staff();

        $this->actingAs($staff)
            ->from('/admin/trainings')
            ->post("/admin/registrations/{$registration->id}/complete", ['force' => true])
            ->assertSessionHasErrors('remarks');

        $this->actingAs($staff)
            ->post("/admin/registrations/{$registration->id}/complete", [
                'force' => true,
                'remarks' => 'Attendance sheet kept on paper; scanner was offline.',
            ])
            ->assertSessionHas('success');

        $this->assertSame(RegistrationStatus::Completed, $registration->fresh()->status);
    }

    public function test_field_office_staff_cannot_mark_another_offices_participant(): void
    {
        [$participant, , $registration] = $this->scenario();

        // Both offices are pinned rather than left to ProfileFactory, which
        // picks one at random — the two could land on the same office and the
        // test would pass for the wrong reason.
        [$theirs, $mine] = FieldOffice::active()->take(2)->get()->all();

        $participant->profile->update(['field_office_id' => $mine->getKey()]);

        $otherOfficeStaff = User::factory()->create([
            'role' => Role::FieldOffice,
            'profile_completed_at' => now(),
            'field_office_id' => $theirs->getKey(),
        ]);

        $this->actingAs($otherOfficeStaff)
            ->post("/admin/registrations/{$registration->id}/attendance", [
                'training_day' => 1,
                'status' => AttendanceStatus::Present->value,
            ])
            ->assertNotFound();

        $this->assertSame(0, Attendance::count());
    }
}
