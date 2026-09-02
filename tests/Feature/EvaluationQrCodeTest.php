<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EvaluationScanOutcome;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\EvaluationDayCode;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\SubjectMatterExpert;
use App\Models\Training;
use App\Models\TrainingDayEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Scanning a training day's evaluation code.
 *
 * The feature is a redirect, so what is worth guarding is not the redirect but
 * everything it refuses to do: send a stranger into somebody's form, open a day
 * that has not happened, survive its own revocation, or answer a wrong guess
 * differently from a right one.
 *
 * The form itself is deliberately untested here — SmeEvaluationTest owns that,
 * and this door reaches it through exactly the same route a participant reaches
 * from their own list.
 */
class EvaluationQrCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday, for the reason SmeEvaluationTest sets out: startingToday()
        // opens at 08:00, and on the wall clock every "the day has begun" case
        // here fails for anyone running the suite before breakfast.
        $this->travelTo(today()->addHours(12));
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    /**
     * A participant on a running training, plus a live code for one of its days.
     *
     * `$expertDays` narrows the expert the same way SmeEvaluationTest's helper
     * does — a whole-run assignment on a multi-day course is rated once, on the
     * last day, so a test that wants day 1 answerable must say so.
     *
     * @param  array<int, int>|null  $expertDays
     * @return array{0: User, 1: Training, 2: Registration, 3: EvaluationDayCode}
     */
    private function scenario(int $days = 1, ?array $expertDays = null, int $codeDay = 1): array
    {
        $participant = $this->participant();
        $training = Training::factory()->startingToday()->runningFor($days)->create();
        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);
        $expert = SubjectMatterExpert::factory()->create();
        $training->subjectMatterExperts()->attach($expert, [
            'sort_order' => 0,
            'days' => $expertDays === null ? null : json_encode($expertDays),
        ]);

        $code = EvaluationDayCode::factory()->forDay($codeDay)->create([
            'training_id' => $training->getKey(),
            'issued_by' => User::factory()->create(['role' => Role::Admin])->getKey(),
        ]);

        return [$participant, $training->refresh(), $registration, $code];
    }

    public function test_scanning_an_open_day_lands_on_that_participants_form(): void
    {
        [$participant, , $registration, $code] = $this->scenario();

        $this->actingAs($participant)
            ->get("/evaluate/{$code->token}")
            ->assertRedirect("/my/evaluations/{$registration->id}/days/1");
    }

    /**
     * The whole point of the feature: the code names a day, never a person.
     *
     * Two participants scanning one poster must each reach their own form. If
     * the token addressed a registration this would be impossible, which is why
     * it does not.
     */
    public function test_two_participants_scanning_one_code_reach_their_own_forms(): void
    {
        [$first, $training, $firstRegistration, $code] = $this->scenario();

        $second = $this->participant();
        $secondRegistration = Registration::factory()->approved()->create([
            'user_id' => $second->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->actingAs($first)
            ->get("/evaluate/{$code->token}")
            ->assertRedirect("/my/evaluations/{$firstRegistration->id}/days/1");

        $this->actingAs($second)
            ->get("/evaluate/{$code->token}")
            ->assertRedirect("/my/evaluations/{$secondRegistration->id}/days/1");
    }

    /**
     * Scanning while signed out walks through login and comes back.
     *
     * This is the single most important UX claim the feature makes, and it
     * rests entirely on the route sitting behind `auth` so Laravel records the
     * intended URL. A route moved outside that group would break this silently.
     */
    public function test_a_logged_out_scan_returns_to_the_code_after_signing_in(): void
    {
        [$participant, , $registration, $code] = $this->scenario();

        $this->get("/evaluate/{$code->token}")
            ->assertRedirect('/login');

        $this->post('/login', [
            'email' => $participant->email,
            'password' => 'password',
        ])->assertRedirect("/evaluate/{$code->token}");

        // And following it through lands where the scan was always going.
        $this->get("/evaluate/{$code->token}")
            ->assertRedirect("/my/evaluations/{$registration->id}/days/1");
    }

    public function test_someone_not_on_the_roster_is_told_so_by_name(): void
    {
        [, $training, , $code] = $this->scenario();
        $outsider = $this->participant();

        $this->actingAs($outsider)
            ->get("/evaluate/{$code->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Evaluations/ScanOutcome')
                ->where('outcome', EvaluationScanOutcome::NotRegistered->value)
                ->where('training.title', $training->title)
                ->etc()
            );
    }

    /**
     * A withdrawn registration reads as "not registered", not as "closed".
     *
     * Different sentence, different remedy: one sends them to re-register, the
     * other sends them to wait for a form that is never coming.
     */
    public function test_a_cancelled_registration_is_not_on_the_roster(): void
    {
        [$participant, , $registration, $code] = $this->scenario();
        $registration->forceFill(['status' => RegistrationStatus::Cancelled])->save();

        $this->actingAs($participant)
            ->get("/evaluate/{$code->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('outcome', EvaluationScanOutcome::NotRegistered->value)
                ->etc()
            );
    }

    /**
     * Scanning the wrong day's poster explains where the form actually went.
     *
     * A four-day run with one expert throughout collects one evaluation, on day
     * four. Scanning day 1 is therefore a thing that will happen, and the reply
     * has to be the service's own sentence rather than a shrug.
     */
    public function test_a_carried_over_day_reports_where_the_form_will_appear(): void
    {
        [$participant, , , $code] = $this->scenario(days: 2, expertDays: null, codeDay: 1);

        $this->actingAs($participant)
            ->get("/evaluate/{$code->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('outcome', EvaluationScanOutcome::Blocked->value)
                ->where('reason', 'This session continues on the next training day — you will evaluate it at the end of day 2.')
                ->where('day', 1)
                ->etc()
            );
    }

    public function test_a_day_that_has_not_happened_yet_is_blocked(): void
    {
        [$participant, , , $code] = $this->scenario(days: 3, expertDays: [3], codeDay: 3);

        $this->actingAs($participant)
            ->get("/evaluate/{$code->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('outcome', EvaluationScanOutcome::Blocked->value)
                ->where('reason', 'This session has not taken place yet.')
                ->etc()
            );
    }

    public function test_a_participant_marked_absent_is_blocked(): void
    {
        [$participant, , $registration, $code] = $this->scenario();

        Attendance::factory()->create([
            'registration_id' => $registration->getKey(),
            'training_day' => 1,
            'status' => AttendanceStatus::Absent,
        ]);

        $this->actingAs($participant)
            ->get("/evaluate/{$code->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('outcome', EvaluationScanOutcome::Blocked->value)
                ->where('reason', 'You were marked absent on this day.')
                ->etc()
            );
    }

    /**
     * A second scan after answering reopens the form rather than refusing.
     *
     * Amending is the existing, deliberate behaviour of the form; the scanning
     * door must not be stricter than the door beside it.
     */
    public function test_scanning_again_after_answering_reopens_the_form(): void
    {
        [$participant, , $registration, $code] = $this->scenario();

        TrainingDayEvaluation::create([
            'training_id' => $registration->training_id,
            'registration_id' => $registration->getKey(),
            'day_number' => 1,
            'submitted_at' => now(),
        ]);

        $this->actingAs($participant)
            ->get("/evaluate/{$code->token}")
            ->assertRedirect("/my/evaluations/{$registration->id}/days/1");
    }

    /**
     * A shortened run leaves its later posters naming a day that is gone.
     *
     * The code stores only (training, day) precisely so this is discoverable at
     * scan time rather than baked in at print time.
     */
    public function test_a_day_dropped_from_a_shortened_run_says_so(): void
    {
        [$participant, $training, , $code] = $this->scenario(days: 3, expertDays: [1, 2, 3], codeDay: 3);

        $training->forceFill(['duration_days' => 1])->save();

        $this->actingAs($participant)
            ->get("/evaluate/{$code->token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('outcome', EvaluationScanOutcome::NoLongerScheduled->value)
                ->etc()
            );
    }

    /**
     * A revoked code and a token that never existed are indistinguishable.
     *
     * Same page, same 404. Asserted together in one test because the property
     * being guarded is the *sameness* — checking each alone would let them
     * drift apart while both still passed.
     */
    public function test_a_revoked_code_and_an_unknown_token_answer_identically(): void
    {
        [$participant, , , $code] = $this->scenario();
        $code->revoke();

        $revoked = $this->actingAs($participant)->get("/evaluate/{$code->token}");
        $unknown = $this->actingAs($participant)->get('/evaluate/'.str_repeat('x', 40));

        $revoked->assertNotFound();
        $unknown->assertNotFound();

        foreach ([$revoked, $unknown] as $response) {
            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Evaluations/ScanOutcome')
                ->where('outcome', EvaluationScanOutcome::Revoked->value)
                ->etc()
            );
        }
    }

    public function test_regenerating_kills_the_previous_token(): void
    {
        [$participant, , , $code] = $this->scenario();
        $staff = User::factory()->create(['role' => Role::Admin]);

        $old = $code->token;
        $code->regenerate($staff);

        $this->actingAs($participant)->get("/evaluate/{$old}")->assertNotFound();
        $this->actingAs($participant)->get("/evaluate/{$code->token}")->assertRedirect();
    }

    /**
     * Scans are counted whatever they lead to.
     *
     * "Forty scans, three responses" is the diagnostic the room actually needs,
     * and it only exists if a refused scan counts the same as an accepted one.
     */
    public function test_every_scan_is_counted_including_refused_ones(): void
    {
        [$participant, , , $code] = $this->scenario();
        $outsider = $this->participant();

        $this->actingAs($participant)->get("/evaluate/{$code->token}");
        $this->actingAs($outsider)->get("/evaluate/{$code->token}");

        $code->refresh();

        $this->assertSame(2, $code->scan_count);
        $this->assertNotNull($code->last_scanned_at);
    }

    /**
     * Generating cuts a code for the days that collect a form, and no others.
     *
     * The feature's central claim. A two-day run delivered by one expert
     * throughout is rated once, at the end — so it gets one poster, for day 2,
     * and a second poster for day 1 would be an invitation to a form that
     * refuses everyone who accepts it.
     */
    public function test_generating_cuts_codes_only_for_days_that_collect_a_form(): void
    {
        [, $training, , $code] = $this->scenario(days: 2, expertDays: null, codeDay: 2);
        $code->delete();

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->post("/admin/trainings/{$training->id}/evaluation-codes")
            ->assertRedirect();

        $this->assertSame(
            [2],
            $training->evaluationDayCodes()->pluck('day_number')->all(),
        );
    }

    /**
     * The admin can cut codes for some days and not others.
     *
     * The expert is booked for days 1 and 3 — two separate stretches, so two
     * evaluation days. A booking of 1, 2 and 3 would be one continuous session
     * rated once on day 3, which is the trap this fixture exists to avoid.
     */
    public function test_generating_honours_the_days_the_admin_picked(): void
    {
        [, $training, , $code] = $this->scenario(days: 3, expertDays: [1, 3], codeDay: 1);
        $code->delete();

        $this->assertSame([1, 3], $training->evaluationDays());

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->post("/admin/trainings/{$training->id}/evaluation-codes", ['days' => [3]])
            ->assertRedirect();

        $this->assertSame(
            [3],
            $training->evaluationDayCodes()->orderBy('day_number')->pluck('day_number')->all(),
        );
    }

    /**
     * A day that collects no form is refused, not quietly dropped.
     *
     * The only way to ask for one is a panel built before the expert
     * assignment changed. Silently ignoring it would leave the admin looking at
     * a day they asked for and did not get, with nothing admitting why.
     */
    public function test_a_day_that_collects_nothing_cannot_be_asked_for(): void
    {
        // Two days, one expert throughout: only day 2 collects.
        [, $training, , $code] = $this->scenario(days: 2, expertDays: null, codeDay: 2);
        $code->delete();

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->post("/admin/trainings/{$training->id}/evaluation-codes", ['days' => [1]])
            ->assertSessionHasErrors('days.0');

        $this->assertSame(0, $training->evaluationDayCodes()->count());
    }

    /** Omitting the selection still means "every evaluation day". */
    public function test_omitting_the_selection_cuts_every_evaluation_day(): void
    {
        [, $training, , $code] = $this->scenario(days: 3, expertDays: [1, 3], codeDay: 1);
        $code->delete();

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->post("/admin/trainings/{$training->id}/evaluation-codes")
            ->assertRedirect();

        $this->assertSame(
            [1, 3],
            $training->evaluationDayCodes()->orderBy('day_number')->pluck('day_number')->all(),
        );
    }

    public function test_generating_is_refused_when_no_expert_is_on_the_panel(): void
    {
        $training = Training::factory()->startingToday()->create();

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->post("/admin/trainings/{$training->id}/evaluation-codes")
            ->assertSessionHas('error');

        $this->assertSame(0, $training->evaluationDayCodes()->count());
    }

    /**
     * Cutting codes is HRD's, narrower than reading results.
     *
     * Management reads evaluation results; deciding what the room is asked is a
     * different act, and the roster withholds the panel from them entirely.
     */
    public function test_only_hrd_may_cut_codes(): void
    {
        [, $training] = $this->scenario();

        foreach ([Role::Management, Role::FieldOffice, Role::CollectingOfficer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post("/admin/trainings/{$training->id}/evaluation-codes")
                ->assertForbidden();
        }
    }

    public function test_the_roster_withholds_the_panel_from_roles_that_cannot_use_it(): void
    {
        [, $training] = $this->scenario();

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('evaluationCodes')->etc());

        $this->actingAs(User::factory()->create(['role' => Role::Management]))
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('evaluationCodes', null)->etc());
    }

    /** The printable sheet carries only the days a poster belongs on. */
    public function test_the_print_sheet_holds_one_page_per_evaluation_day(): void
    {
        [, $training, , $code] = $this->scenario(days: 2, expertDays: null, codeDay: 2);

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->get("/admin/trainings/{$training->id}/evaluation-codes/print")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Evaluations/Codes')
                ->has('sheets', 1)
                ->where('sheets.0.day', 2)
                ->where('sheets.0.url', $code->url())
                ->etc()
            );
    }

    /** Issuing is idempotent: pressing "generate" twice keeps the printed code. */
    public function test_issuing_a_code_twice_returns_the_same_one(): void
    {
        [, $training] = $this->scenario();
        $staff = User::factory()->create(['role' => Role::Admin]);

        $first = EvaluationDayCode::issue($training, 2, $staff);
        $second = EvaluationDayCode::issue($training, 2, $staff);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($first->token, $second->token);
    }
}
