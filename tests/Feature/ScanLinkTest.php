<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\ScanLink;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ScanLinkFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The public attendance station.
 *
 * This is the only unauthenticated surface in the app that can read participant
 * identities in bulk and write attendance, so the tests here are weighted
 * accordingly: most of them are about what the station refuses to do.
 *
 * The invariant worth stating once — a scan link can never see or write more
 * than the staff member who issued it could. Everything else follows from that.
 */
class ScanLinkTest extends TestCase
{
    use RefreshDatabase;

    private function issuer(Role $role = Role::Admin, ?FieldOffice $office = null): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
        ]);

        return $user->refresh();
    }

    /**
     * A live link on a training running today, with one approved participant.
     *
     * @return array{0: ScanLink, 1: Training, 2: Registration, 3: User}
     */
    private function scenario(array $linkState = []): array
    {
        $training = Training::factory()->startingToday()->runningFor(1)->create();

        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $link = ScanLink::factory()->state($linkState)->create([
            'training_id' => $training->getKey(),
            'issued_by' => $this->issuer()->getKey(),
        ]);

        return [$link, $training, $registration, $participant->refresh()];
    }

    /** Unlock a station the way the page does, returning the device's grant. */
    private function grantFor(ScanLink $link, string $code = ScanLinkFactory::CODE): string
    {
        return $this->postJson("/station/{$link->token}/unlock", ['code' => $code])
            ->assertOk()
            ->json('grant');
    }

    /* ---------------------------------------------------------------------- */
    /* The gate */
    /* ---------------------------------------------------------------------- */

    public function test_the_station_page_reveals_nothing_before_the_code_is_entered(): void
    {
        [$link, $training, , $participant] = $this->scenario();

        $response = $this->get("/station/{$link->token}")->assertOk();

        // The training's name is fine — it confirms you are at the right door.
        // A participant's name is not, and this payload is readable by anyone
        // who has the URL alone.
        $response->assertDontSee($participant->name);

        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Scan/Station')
                ->where('state', 'active')
                ->where('link.training_title', $training->title)
                ->missing('participants')
        );
    }

    public function test_a_wrong_code_is_refused(): void
    {
        [$link] = $this->scenario();

        $this->postJson("/station/{$link->token}/unlock", ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_an_unknown_token_and_a_wrong_code_are_indistinguishable(): void
    {
        [$link] = $this->scenario();

        // Same status and same message, so the gate cannot be used to discover
        // which tokens exist.
        $wrongCode = $this->postJson("/station/{$link->token}/unlock", ['code' => '000000'])
            ->assertStatus(422);

        $unknownToken = $this->postJson('/station/nonexistent-token/unlock', ['code' => '123456'])
            ->assertStatus(422);

        $this->assertSame(
            $wrongCode->json('errors.code'),
            $unknownToken->json('errors.code'),
        );
    }

    public function test_expired_and_revoked_links_cannot_be_unlocked(): void
    {
        [$expired] = $this->scenario(['expires_at' => CarbonImmutable::now()->subDay()]);
        [$revoked] = $this->scenario(['revoked_at' => CarbonImmutable::now()->subHour()]);

        $this->postJson("/station/{$expired->token}/unlock", ['code' => ScanLinkFactory::CODE])
            ->assertStatus(422);

        $this->postJson("/station/{$revoked->token}/unlock", ['code' => ScanLinkFactory::CODE])
            ->assertStatus(422);

        // And the page explains itself rather than 404ing at a venue door.
        $this->get("/station/{$expired->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired')->where('link', null));

        $this->get("/station/{$revoked->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'revoked'));
    }

    /* ---------------------------------------------------------------------- */
    /* The roster */
    /* ---------------------------------------------------------------------- */

    public function test_the_roster_requires_a_grant(): void
    {
        [$link] = $this->scenario();

        $this->getJson("/station/{$link->token}/roster")->assertStatus(401);
        $this->getJson("/station/{$link->token}/roster", ['X-Scan-Grant' => 'nonsense'])->assertStatus(401);
    }

    public function test_an_unlocked_station_downloads_the_roster_with_digests_not_codes(): void
    {
        [$link, , $registration, $participant] = $this->scenario();

        $response = $this->getJson("/station/{$link->token}/roster", [
            'X-Scan-Grant' => $this->grantFor($link),
        ])->assertOk();

        $response->assertJsonPath('participants.0.registration_id', $registration->id);
        $response->assertJsonPath('participants.0.name', $participant->name);

        // Re-read: the download is what mints the token, so the copy held here
        // from before the request knows nothing about it.
        $token = $participant->fresh()->ensureQrToken();

        // The digest, never the token itself — a phone left at the venue must
        // carry no working check-in codes.
        $response->assertJsonPath('participants.0.token_hash', hash('sha256', $token));
        $response->assertDontSee($token);
    }

    public function test_a_grant_is_bound_to_the_link_that_issued_it(): void
    {
        [$first] = $this->scenario();
        [$second] = $this->scenario();

        $grant = $this->grantFor($first);

        // Presenting one door's credential at another must not open it.
        $this->getJson("/station/{$second->token}/roster", ['X-Scan-Grant' => $grant])
            ->assertStatus(401);
    }

    public function test_revoking_a_link_kills_grants_already_in_the_wild(): void
    {
        [$link] = $this->scenario();
        $grant = $this->grantFor($link);

        $this->getJson("/station/{$link->token}/roster", ['X-Scan-Grant' => $grant])->assertOk();

        $link->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        // The point of revocation: the phone already holds a valid-looking
        // credential, and it has to stop working the moment the office says so.
        $this->getJson("/station/{$link->token}/roster", ['X-Scan-Grant' => $grant])->assertStatus(401);
    }

    public function test_the_roster_is_scoped_to_the_issuers_field_office(): void
    {
        $office = FieldOffice::factory()->create();
        $other = FieldOffice::factory()->create();

        $training = Training::factory()->startingToday()->runningFor(1)->create();

        $mine = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($mine)->create(['field_office_id' => $office->getKey()]);
        Registration::factory()->approved()->create([
            'user_id' => $mine->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $theirs = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($theirs)->create(['field_office_id' => $other->getKey()]);
        Registration::factory()->approved()->create([
            'user_id' => $theirs->getKey(),
            'training_id' => $training->getKey(),
        ]);

        // Issued by a field-office user, so the link inherits their horizon.
        $link = ScanLink::factory()->create([
            'training_id' => $training->getKey(),
            'issued_by' => $this->issuer(Role::FieldOffice, $office)->getKey(),
        ]);

        $response = $this->getJson("/station/{$link->token}/roster", [
            'X-Scan-Grant' => $this->grantFor($link),
        ])->assertOk();

        $response->assertJsonCount(1, 'participants');
        $response->assertJsonPath('participants.0.name', $mine->name);
        $response->assertDontSee($theirs->name);
    }

    /* ---------------------------------------------------------------------- */
    /* Writing back */
    /* ---------------------------------------------------------------------- */

    public function test_a_queued_scan_records_attendance_at_the_time_it_happened(): void
    {
        [$link, $training, $registration] = $this->scenario();

        // The moment at the door, hours before the queue drained.
        $at = CarbonImmutable::now()->startOfDay()->addHours(8);

        $this->postJson("/station/{$link->token}/sync", [
            'scans' => [[
                'client_id' => 'abc-123',
                'registration_id' => $registration->id,
                'scanned_at' => $at->toIso8601String(),
            ]],
        ], ['X-Scan-Grant' => $this->grantFor($link)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced');

        $attendance = Attendance::sole();

        $this->assertSame($registration->id, $attendance->registration_id);
        $this->assertSame(1, $attendance->training_day);
        $this->assertNotNull($attendance->time_in);
        // Attributed to the staff member who put a station on that door.
        $this->assertSame($link->issued_by, $attendance->recorded_by);
        $this->assertSame($training->id, $registration->fresh()->training_id);
    }

    public function test_a_repeated_scan_is_reported_as_a_duplicate_and_writes_once(): void
    {
        [$link, , $registration] = $this->scenario();
        $grant = $this->grantFor($link);

        $payload = fn (string $clientId) => [
            'scans' => [[
                'client_id' => $clientId,
                'registration_id' => $registration->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ];

        $this->postJson("/station/{$link->token}/sync", $payload('one'), ['X-Scan-Grant' => $grant])
            ->assertJsonPath('results.0.status', 'synced');

        $this->postJson("/station/{$link->token}/sync", $payload('two'), ['X-Scan-Grant' => $grant])
            ->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, Attendance::count());
    }

    public function test_sync_requires_a_grant(): void
    {
        [$link, , $registration] = $this->scenario();

        $this->postJson("/station/{$link->token}/sync", [
            'scans' => [[
                'client_id' => 'abc',
                'registration_id' => $registration->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ])->assertStatus(401);

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_station_cannot_write_attendance_for_another_training(): void
    {
        [$link] = $this->scenario();

        // A participant on a different training entirely.
        [, , $elsewhere] = $this->scenario();

        $this->postJson("/station/{$link->token}/sync", [
            'scans' => [[
                'client_id' => 'abc',
                'registration_id' => $elsewhere->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ], ['X-Scan-Grant' => $this->grantFor($link)])
            ->assertOk()
            // Refused rather than silently dropped, so the device stops retrying.
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_scan_outside_the_issuers_office_is_refused(): void
    {
        $office = FieldOffice::factory()->create();
        $training = Training::factory()->startingToday()->runningFor(1)->create();

        $outsider = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($outsider)->create([
            'field_office_id' => FieldOffice::factory()->create()->getKey(),
        ]);
        $registration = Registration::factory()->approved()->create([
            'user_id' => $outsider->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $link = ScanLink::factory()->create([
            'training_id' => $training->getKey(),
            'issued_by' => $this->issuer(Role::FieldOffice, $office)->getKey(),
        ]);

        // The roster never offered this person, so a payload naming them is
        // either a stale device or a tampered request. Either way, no write.
        $this->postJson("/station/{$link->token}/sync", [
            'scans' => [[
                'client_id' => 'abc',
                'registration_id' => $registration->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ], ['X-Scan-Grant' => $this->grantFor($link)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, Attendance::count());
    }

    /* ---------------------------------------------------------------------- */
    /* Practice stations */
    /* ---------------------------------------------------------------------- */

    public function test_a_practice_station_answers_normally_but_records_nothing(): void
    {
        [$link, , $registration] = $this->scenario(['is_test' => true]);

        $this->postJson("/station/{$link->token}/sync", [
            'scans' => [[
                'client_id' => 'abc',
                'registration_id' => $registration->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ], ['X-Scan-Grant' => $this->grantFor($link)])
            ->assertOk()
            // The same verdict a live scan would have produced …
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.dry_run', true)
            ->assertJsonPath('results.0.training_day', 1);

        // … and no trace of it anywhere.
        $this->assertSame(0, Attendance::count());
        $this->assertNull($registration->fresh()->attended_at);
    }

    public function test_a_practice_station_still_refuses_what_a_real_one_would(): void
    {
        [$link] = $this->scenario(['is_test' => true]);
        [, , $elsewhere] = $this->scenario();

        // A rehearsal that reported success for a scan the live station would
        // reject would be worse than no rehearsal at all.
        $this->postJson("/station/{$link->token}/sync", [
            'scans' => [[
                'client_id' => 'abc',
                'registration_id' => $elsewhere->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ], ['X-Scan-Grant' => $this->grantFor($link)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, Attendance::count());
    }

    /**
     * A training that has not started yet, with one approved participant.
     *
     * The default TrainingFactory already starts a week out, which is exactly
     * the situation a rehearsal happens in.
     *
     * @return array{0: ScanLink, 1: Registration}
     */
    private function futureScenario(array $linkState = []): array
    {
        $training = Training::factory()->runningFor(1)->create();

        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $link = ScanLink::factory()->state($linkState)->create([
            'training_id' => $training->getKey(),
            'issued_by' => $this->issuer()->getKey(),
        ]);

        return [$link, $registration];
    }

    public function test_a_practice_station_can_rehearse_a_training_that_is_not_running_today(): void
    {
        [$link, $registration] = $this->futureScenario(['is_test' => true]);

        // The whole point of rehearsing: it happens days before the session, so
        // refusing every scan as "not running today" would test nothing.
        $this->postJson("/station/{$link->token}/sync", [
            'scans' => [[
                'client_id' => 'abc',
                'registration_id' => $registration->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ], ['X-Scan-Grant' => $this->grantFor($link)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.dry_run', true)
            // Day 1 stood in for the rehearsal.
            ->assertJsonPath('results.0.training_day', 1);

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_live_station_still_refuses_a_training_that_is_not_running_today(): void
    {
        [$link, $registration] = $this->futureScenario();

        // The off-day guard is absolute on a real door — landing a mis-scanned
        // code on day 1 is precisely what it exists to stop.
        $this->postJson("/station/{$link->token}/sync", [
            'scans' => [[
                'client_id' => 'abc',
                'registration_id' => $registration->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ], ['X-Scan-Grant' => $this->grantFor($link)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, Attendance::count());
    }

    public function test_only_a_superadmin_can_issue_a_practice_station(): void
    {
        $training = Training::factory()->startingToday()->create();

        $this->actingAs($this->issuer(Role::Admin))
            ->post("/admin/trainings/{$training->id}/scan-links", ['is_test' => true])
            ->assertForbidden();

        $this->assertSame(0, ScanLink::count());

        $this->actingAs($this->issuer(Role::SuperAdmin))
            ->post("/admin/trainings/{$training->id}/scan-links", ['is_test' => true])
            ->assertRedirect();

        $this->assertTrue(ScanLink::sole()->is_test);
    }

    public function test_only_a_superadmin_can_dry_run_the_staff_scanner(): void
    {
        [, $training, $registration] = $this->scenario();

        $payload = [
            'training_id' => $training->id,
            'dry_run' => true,
            'scans' => [[
                'client_id' => 'abc',
                'registration_id' => $registration->id,
                'scanned_at' => CarbonImmutable::now()->toIso8601String(),
            ]],
        ];

        $this->actingAs($this->issuer(Role::Admin))
            ->postJson('/admin/scanner/sync', $payload)
            ->assertForbidden();

        // Refused outright rather than downgraded to a live write.
        $this->assertSame(0, Attendance::count());

        $this->actingAs($this->issuer(Role::SuperAdmin))
            ->postJson('/admin/scanner/sync', $payload)
            ->assertOk()
            ->assertJsonPath('results.0.dry_run', true);

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_live_staff_sync_still_writes(): void
    {
        [, $training, $registration] = $this->scenario();

        $this->actingAs($this->issuer(Role::SuperAdmin))
            ->postJson('/admin/scanner/sync', [
                'training_id' => $training->id,
                'scans' => [[
                    'client_id' => 'abc',
                    'registration_id' => $registration->id,
                    'scanned_at' => CarbonImmutable::now()->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced');

        // The guard against a dry run that leaked into the default path.
        $this->assertSame(1, Attendance::count());
    }

    /* ---------------------------------------------------------------------- */
    /* Issuing */
    /* ---------------------------------------------------------------------- */

    public function test_staff_can_issue_a_link_and_see_its_code_exactly_once(): void
    {
        $training = Training::factory()->startingToday()->create();

        $this->actingAs($this->issuer())
            ->post("/admin/trainings/{$training->id}/scan-links", ['label' => 'Front door'])
            ->assertRedirect()
            ->assertSessionHas('scan_link');

        $link = ScanLink::sole();

        $this->assertSame('Front door', $link->label);
        $this->assertTrue($link->isActive());
        // Stored hashed, so the code cannot be recovered from the row.
        $this->assertNotSame(session('scan_link.code'), $link->code_hash);
        $this->assertTrue($link->verifyCode(session('scan_link.code')));
    }

    public function test_a_participant_cannot_issue_or_revoke_links(): void
    {
        $training = Training::factory()->startingToday()->create();
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $this->actingAs($participant)
            ->post("/admin/trainings/{$training->id}/scan-links")
            ->assertForbidden();

        [$link] = $this->scenario();

        $this->actingAs($participant)
            ->delete("/admin/scan-links/{$link->id}")
            ->assertForbidden();

        $this->assertNull($link->fresh()->revoked_at);
    }

    /**
     * Using a link must not shorten its life.
     *
     * This looks tautological and is not. `expires_at` was originally a
     * TIMESTAMP column, and MySQL attaches ON UPDATE CURRENT_TIMESTAMP to the
     * first NOT NULL TIMESTAMP column that declares no default — so writing
     * `last_used_at` on the first unlock silently reset the expiry to now and
     * killed the link the moment anyone used it.
     *
     * SQLite never reproduced it, which is exactly why the assertion is written
     * down: it fails loudly on MySQL if the column type is ever changed back.
     */
    public function test_unlocking_does_not_move_the_expiry(): void
    {
        [$link] = $this->scenario();
        $before = $link->expires_at;

        $this->grantFor($link);

        $link->refresh();

        $this->assertTrue($link->isActive());
        $this->assertNotNull($link->last_used_at);
        $this->assertSame($before->toDateTimeString(), $link->expires_at->toDateTimeString());
    }

    public function test_revoking_is_recorded_rather_than_deleting_the_row(): void
    {
        [$link] = $this->scenario();

        $this->actingAs($this->issuer())
            ->delete("/admin/scan-links/{$link->id}")
            ->assertRedirect();

        // The row survives: it is the only record of who authorised a door.
        $this->assertNotNull($link->fresh()->revoked_at);
        $this->assertFalse($link->fresh()->isActive());
    }
}
