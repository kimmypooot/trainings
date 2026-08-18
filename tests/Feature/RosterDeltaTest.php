<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asking a roster what has changed since last time.
 *
 * A station downloads a training's whole roster before the doors open and then
 * works offline. On a forty-person seminar that bundle is nothing; on a
 * thousand-seat event with walk-ins trickling in all morning, re-downloading it
 * to learn about one new person is what actually falls over.
 *
 * The delta has to carry three things or a device drifts out of step with the
 * hall: people newly added, people marked by another station, and people whose
 * registration was pulled. The last is the one absence cannot express, because
 * a delta merges rather than replaces.
 */
class RosterDeltaTest extends TestCase
{
    use RefreshDatabase;

    private function staff(?FieldOffice $office = null): User
    {
        return User::factory()->create([
            'role' => $office ? Role::FieldOffice : Role::Admin,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
        ])->refresh();
    }

    private function participant(Training $training, ?FieldOffice $office = null): Registration
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office?->getKey()]);
        $user->ensureQrToken();

        return Registration::factory()->approved()->create([
            'user_id' => $user->getKey(),
            'training_id' => $training->getKey(),
        ]);
    }

    private function roster(User $actor, Training $training, ?string $since = null): array
    {
        $url = route('admin.scanner.roster', $training).($since ? '?since='.urlencode($since) : '');

        return $this->actingAs($actor)->getJson($url)->assertOk()->json();
    }

    public function test_a_full_bundle_carries_everyone_and_no_delta_fields(): void
    {
        $training = Training::factory()->startingToday()->runningFor(1)->create();
        $this->participant($training);
        $this->participant($training);

        $bundle = $this->roster($this->staff(), $training);

        $this->assertCount(2, $bundle['participants']);

        // Omitted rather than sent empty: a device reading this back out of
        // IndexedDB must be able to tell a full bundle from a delta.
        $this->assertArrayNotHasKey('removed', $bundle);
        $this->assertArrayNotHasKey('partial', $bundle);
    }

    /**
     * The overlap is deliberate, so the tests step around it.
     *
     * Watermarks are compared with >= against second-resolution timestamps, so
     * a row written in the same second as a watermark is re-sent. That is the
     * safe direction — the merge is by registration id, so a duplicate costs
     * nothing, whereas a miss is permanent. Each test therefore lets the clock
     * move on before reading a watermark, so what it asserts is the delta and
     * not the overlap.
     */
    public function test_a_delta_carries_only_what_changed(): void
    {
        $training = Training::factory()->startingToday()->runningFor(1)->create();
        $this->participant($training);

        $staff = $this->staff();
        $this->travel(2)->seconds();

        $watermark = $this->roster($staff, $training)['downloaded_at'];

        $this->travel(2)->seconds();

        $walkIn = $this->participant($training);

        $delta = $this->roster($staff, $training, $watermark);

        $this->assertTrue($delta['partial']);
        $this->assertCount(1, $delta['participants']);
        $this->assertSame(
            $walkIn->getKey(),
            $delta['participants'][0]['registration_id'],
        );
    }

    /**
     * Attendance moves without the registration moving.
     *
     * A station across the room marking somebody writes an attendance row and
     * leaves the registration untouched. A delta watching only registrations
     * would keep serving that participant as unmarked, and both doors would
     * greet them as a fresh arrival.
     */
    public function test_a_delta_includes_someone_another_station_marked(): void
    {
        $training = Training::factory()->startingToday()->runningFor(1)->create();
        $registration = $this->participant($training);

        $staff = $this->staff();
        $this->travel(2)->seconds();

        $watermark = $this->roster($staff, $training)['downloaded_at'];

        $this->travel(2)->seconds();

        AttendanceService::checkIn($registration, $staff, CarbonImmutable::now());

        $delta = $this->roster($staff, $training, $watermark);

        $this->assertCount(1, $delta['participants']);
        $this->assertArrayHasKey('1', $delta['participants'][0]['attendance']);
    }

    public function test_a_cancelled_registration_comes_back_as_removed(): void
    {
        $training = Training::factory()->startingToday()->runningFor(1)->create();
        $registration = $this->participant($training);

        $staff = $this->staff();
        $this->travel(2)->seconds();

        $watermark = $this->roster($staff, $training)['downloaded_at'];

        $this->travel(2)->seconds();

        $registration->forceFill([
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        $delta = $this->roster($staff, $training, $watermark);

        $this->assertSame([], $delta['participants']);
        $this->assertSame([$registration->getKey()], $delta['removed']);
    }

    public function test_a_quiet_interval_returns_an_empty_delta(): void
    {
        $training = Training::factory()->startingToday()->runningFor(1)->create();
        $this->participant($training);

        $staff = $this->staff();
        $this->travel(2)->seconds();

        $watermark = $this->roster($staff, $training)['downloaded_at'];

        $this->travel(2)->seconds();

        $delta = $this->roster($staff, $training, $watermark);

        $this->assertSame([], $delta['participants']);
        $this->assertSame([], $delta['removed']);
    }

    /**
     * The scoping invariant holds on the new path too.
     *
     * A delta is a second way to read a roster, and every way of reading one
     * has to narrow to the caller's own office — see FieldOfficeScopingTest.
     */
    public function test_a_delta_stays_inside_the_callers_field_office(): void
    {
        $mine = FieldOffice::factory()->create();
        $theirs = FieldOffice::factory()->create();

        $training = Training::factory()->startingToday()->runningFor(1)->create();

        $staff = $this->staff($mine);
        $this->travel(2)->seconds();

        $watermark = $this->roster($staff, $training)['downloaded_at'];

        $this->travel(2)->seconds();

        $ours = $this->participant($training, $mine);
        $this->participant($training, $theirs);

        $delta = $this->roster($staff, $training, $watermark);

        $this->assertCount(1, $delta['participants']);
        $this->assertSame($ours->getKey(), $delta['participants'][0]['registration_id']);
    }

    /**
     * A watermark that cannot be read is answered, not rejected.
     *
     * The station is the party a 422 would strand mid-session, and a full
     * bundle is always a correct — merely expensive — answer to "what changed".
     */
    public function test_an_unreadable_watermark_falls_back_to_a_full_bundle(): void
    {
        $training = Training::factory()->startingToday()->runningFor(1)->create();
        $this->participant($training);

        $bundle = $this->roster($this->staff(), $training, 'not-a-date');

        $this->assertCount(1, $bundle['participants']);
        $this->assertArrayNotHasKey('partial', $bundle);
    }
}
