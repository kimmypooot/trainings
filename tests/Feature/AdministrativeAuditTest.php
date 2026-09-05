<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The trail covered the workflow and not the administrators.
 *
 * `ActivityLogger` was called from every domain service — payments, refunds,
 * registrations, certificates, agency requests — so a participant's journey was
 * thoroughly recorded. What none of it covered was the people running the
 * system: account creation, **role changes**, deactivation, a member of staff
 * editing somebody else's personal data, and bulk export of the register. Those
 * are the first things an auditor asks about, and the trail could not answer
 * one of them.
 *
 * Each case here asserts the entry exists, names the actor, and — where the
 * previous value is not recoverable afterwards — carries it.
 */
class AdministrativeAuditTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => Role::SuperAdmin, 'profile_completed_at' => now()]);
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    private function entry(string $action): ActivityLog
    {
        $log = ActivityLog::where('action', $action)->latest('id')->first();

        $this->assertNotNull($log, "Nothing was recorded for [{$action}].");

        return $log;
    }

    // ------------------------------------------------------- account admin

    public function test_creating_a_staff_account_is_recorded(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post('/admin/users', [
            'name' => 'Nueva Officer',
            'email' => 'nueva@csc.gov.ph',
            'role' => Role::Admin->value,
            'password' => 'CreatedByAudit123',
            'password_confirmation' => 'CreatedByAudit123',
        ])->assertRedirect();

        $log = $this->entry('user.created');

        $this->assertSame($actor->getKey(), $log->causer_id);
        $this->assertSame(Role::Admin->value, $log->properties['role']);
    }

    /**
     * The single most important entry in the file. "Who made this person a
     * superadmin, and what were they before" is unanswerable from the record
     * afterwards — the row only holds the new value — so the previous one has
     * to be captured at the moment it changes.
     */
    public function test_a_role_change_records_what_it_changed_from(): void
    {
        $actor = $this->superAdmin();
        $target = User::factory()->create(['role' => Role::FieldOffice, 'profile_completed_at' => now()]);

        $this->actingAs($actor)->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => Role::SuperAdmin->value,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $log = $this->entry('user.updated');

        $this->assertSame($actor->getKey(), $log->causer_id);
        $this->assertContains('role', $log->properties['changed']);
        $this->assertSame(Role::FieldOffice->value, $log->properties['from']['role']);
        $this->assertSame(Role::SuperAdmin->value, $log->properties['to']['role']);
    }

    /**
     * A form posts every field whether or not it moved, so an entry listing all
     * of them would bury a role change among four that were re-submitted
     * unchanged — a trail nobody can scan is a trail nobody reads.
     */
    public function test_an_edit_that_changes_nothing_records_nothing(): void
    {
        $target = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->actingAs($this->superAdmin())->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => Role::Admin->value,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, ActivityLog::where('action', 'user.updated')->count());
    }

    /**
     * That the password changed belongs in the trail; what it changed to does
     * not. A hash in activity_logs is an offline cracking target sitting in a
     * table more people can read than can read `users`.
     */
    public function test_a_password_reset_by_an_administrator_records_no_secret(): void
    {
        $target = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->actingAs($this->superAdmin())->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => Role::Admin->value,
            'is_active' => true,
            'password' => 'ReplacedByAdmin123',
            'password_confirmation' => 'ReplacedByAdmin123',
        ])->assertSessionHasNoErrors();

        $log = $this->entry('user.updated');
        $encoded = json_encode($log->properties);

        $this->assertContains('password', $log->properties['changed']);
        $this->assertStringNotContainsString('ReplacedByAdmin123', $encoded);
        $this->assertStringNotContainsString('$2y$', $encoded, 'A password hash reached the audit trail.');
    }

    public function test_deactivating_a_staff_account_is_recorded(): void
    {
        $target = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->actingAs($this->superAdmin())
            ->post("/admin/users/{$target->id}/toggle")
            ->assertRedirect();

        $this->assertSame('deactivated', $this->entry('user.deactivated')->properties['to']);
    }

    // ---------------------------------------------------- participant data

    public function test_staff_editing_a_participants_profile_is_recorded(): void
    {
        $actor = $this->superAdmin();
        $participant = $this->participant();

        $payload = array_merge($participant->profile->only([
            'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'civil_status',
            'mobile_number', 'position_title', 'salary_grade', 'organization_name',
            'sector', 'region', 'province', 'city_municipality', 'field_office_id',
            'position_level', 'employment_status', 'organization_address',
        ]), [
            'date_of_birth' => $participant->profile->date_of_birth?->format('Y-m-d'),
            'is_pwd' => $participant->profile->is_pwd ? 'Yes' : 'No',
            'organization_name' => 'Provincial Government of Leyte',
        ]);

        $this->actingAs($actor)
            ->put("/admin/participants/{$participant->id}", $payload)
            ->assertSessionHasNoErrors();

        $log = $this->entry('participant.profile_updated');

        $this->assertSame($actor->getKey(), $log->causer_id);
        $this->assertContains('organization_name', $log->properties['changed']);
    }

    /**
     * Field names, never values. The profile holds a date of birth, a mobile
     * number, PWD status and dietary needs; copying those into activity_logs
     * would spread sensitive data into a second table with a wider audience,
     * which is the opposite of what auditing them is for.
     */
    public function test_the_profile_entry_carries_no_sensitive_values(): void
    {
        $participant = $this->participant();
        $participant->profile->forceFill(['mobile_number' => '09171234567'])->save();

        $payload = array_merge($participant->profile->only([
            'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'civil_status',
            'position_title', 'salary_grade', 'organization_name', 'sector', 'region',
            'province', 'city_municipality', 'field_office_id', 'position_level',
            'employment_status', 'organization_address',
        ]), [
            'date_of_birth' => $participant->profile->date_of_birth?->format('Y-m-d'),
            'is_pwd' => $participant->profile->is_pwd ? 'Yes' : 'No',
            'mobile_number' => '09189998888',
        ]);

        $this->actingAs($this->superAdmin())
            ->put("/admin/participants/{$participant->id}", $payload)
            ->assertSessionHasNoErrors();

        $log = $this->entry('participant.profile_updated');

        $this->assertContains('mobile_number', $log->properties['changed']);
        $this->assertStringNotContainsString('09189998888', json_encode($log->properties));
        $this->assertStringNotContainsString('09171234567', json_encode($log->properties));
    }

    public function test_mailing_a_participant_a_reset_link_is_recorded(): void
    {
        Notification::fake();

        $actor = $this->superAdmin();
        $participant = $this->participant();

        $this->actingAs($actor)
            ->post("/admin/participants/{$participant->id}/password-reset")
            ->assertRedirect();

        $this->assertSame($actor->getKey(), $this->entry('participant.password_reset_sent')->causer_id);
    }

    // ----------------------------------------------------------- exports

    /**
     * The largest hole: the participants export carries every date of birth and
     * mobile number in the region, and taking a copy left no trace at all. The
     * office could not answer "who has a copy of the register, and from when".
     */
    public function test_an_export_is_recorded_with_its_filters(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->get('/admin/exports/participants?sector=National+Government&format=csv')
            ->assertSuccessful();

        $log = $this->entry('export.downloaded');

        $this->assertSame($actor->getKey(), $log->causer_id);
        $this->assertSame('the participants register', $log->properties['export']);
        $this->assertSame('National Government', $log->properties['filters']['sector']);
        $this->assertSame('csv', $log->properties['format']);
    }

    // --------------------------------------------------------- attendance

    /**
     * Attendance rows already carry `recorded_by`, so a scan is not unrecorded.
     * What they cannot show is a *decision* — a Present a member of staff
     * turned into Absent — and that is what a dispute is always about.
     */
    public function test_correcting_attendance_by_hand_records_both_sides(): void
    {
        $actor = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
        $participant = $this->participant();
        $training = Training::factory()->startingToday()->runningFor(1)->create();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->actingAs($actor)->post("/admin/registrations/{$registration->id}/attendance", [
            'training_day' => 1,
            'status' => AttendanceStatus::Present->value,
        ])->assertRedirect();

        $this->actingAs($actor)->post("/admin/registrations/{$registration->id}/attendance", [
            'training_day' => 1,
            'status' => AttendanceStatus::Absent->value,
            'remarks' => 'Mis-scan at the door.',
        ])->assertRedirect();

        $log = $this->entry('attendance.marked');

        $this->assertSame($actor->getKey(), $log->causer_id);
        $this->assertSame(AttendanceStatus::Present->value, $log->properties['from']);
        $this->assertSame(AttendanceStatus::Absent->value, $log->properties['to']);
        $this->assertSame('Mis-scan at the door.', $log->properties['remarks']);
    }

    /**
     * The asymmetry is deliberate and worth pinning: logging every scan would
     * add a row per participant per day — thousands over a large event — and
     * bury the handful of overrides inside them.
     */
    public function test_an_ordinary_scan_does_not_write_an_activity_row(): void
    {
        $actor = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
        $participant = $this->participant();
        $training = Training::factory()->startingToday()->runningFor(1)->create();

        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->actingAs($actor)
            ->post("/scan/{$participant->ensureQrToken()}/check-in", [
                'registration_id' => Registration::where('user_id', $participant->getKey())->value('id'),
            ])
            ->assertRedirect();

        $this->assertSame(0, ActivityLog::where('action', 'attendance.marked')->count());
    }
}
