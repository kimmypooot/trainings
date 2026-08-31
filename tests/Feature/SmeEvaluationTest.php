<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\EvaluationInvitation;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\SmeEvaluation;
use App\Models\SubjectMatterExpert;
use App\Models\Training;
use App\Models\TrainingDayEvaluation;
use App\Models\User;
use App\Notifications\EvaluationRequested;
use App\Support\SmeEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Evaluating the subject matter experts who delivered a training day.
 *
 * The invariants worth guarding are all about *who may say what about whom*:
 * a day that has not happened cannot be rated, an expert who was not there
 * cannot be rated, somebody else's registration cannot be rated at all, and a
 * participant who files twice corrects their answers rather than voting twice.
 */
class SmeEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Midday, so the run in `scenario()` has actually begun.
         *
         * Both the participant's list and the admin index ask only about
         * trainings that have started (`starts_at <= now()`), and the factory's
         * startingToday() state opens at 08:00. Left on the wall clock, every
         * test here that counts an outstanding evaluation passed all afternoon
         * and failed for anyone running the suite before breakfast — a real
         * eight-hour window each day, and the kind of failure that gets
         * re-run rather than read. Same fix DashboardTest already applies.
         */
        $this->travelTo(today()->addHours(12));
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    private function staff(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    /**
     * A participant approved on a running training with one expert on the panel.
     *
     * `$expertDays` narrows that expert to particular days; null is the
     * whole-run assignment. It matters more than it looks: an expert who is
     * back tomorrow is not evaluated tonight, so on a multi-day run a whole-run
     * assignment is rated once, on the final day, and every intermediate day is
     * closed. A test that wants day 1 answerable has to say the expert finishes
     * on day 1.
     *
     * @param  array<int, int>|null  $expertDays
     * @return array{0: User, 1: Training, 2: Registration, 3: SubjectMatterExpert}
     */
    private function scenario(int $days = 1, ?array $expertDays = null): array
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

        return [$participant, $training->refresh(), $registration, $expert];
    }

    /**
     * @return array<string, mixed>
     */
    private function answers(SubjectMatterExpert $expert, int $rating = 5): array
    {
        return [
            'learned' => 'How to file the report properly.',
            'ratings' => [
                $expert->getKey() => [
                    'knowledge_rating' => $rating,
                    'interaction_rating' => $rating,
                    'engagement_rating' => $rating,
                    'pace_rating' => $rating,
                    'comments' => 'Clear and patient.',
                ],
            ],
        ];
    }

    public function test_a_participant_can_evaluate_the_days_expert(): void
    {
        [$participant, , $registration, $expert] = $this->scenario();

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert))
            ->assertRedirect('/my/evaluations');

        $evaluation = TrainingDayEvaluation::firstOrFail();

        $this->assertSame($registration->getKey(), $evaluation->registration_id);
        $this->assertSame(1, $evaluation->day_number);
        $this->assertSame('How to file the report properly.', $evaluation->learned);

        $rating = SmeEvaluation::firstOrFail();

        $this->assertSame($expert->getKey(), $rating->subject_matter_expert_id);
        $this->assertSame(5, $rating->knowledge_rating->value);
        $this->assertSame(5.0, $rating->averageRating());
    }

    /** The day, the participant, the training and the expert are all recorded. */
    public function test_an_evaluation_is_tied_to_training_day_participant_and_expert(): void
    {
        // Day 1 only, so the expert finishes today and day 1 is answerable.
        [$participant, $training, $registration, $expert] = $this->scenario(2, [1]);

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert));

        $this->assertDatabaseHas('training_day_evaluations', [
            'training_id' => $training->getKey(),
            'registration_id' => $registration->getKey(),
            'day_number' => 1,
        ]);

        $this->assertDatabaseHas('sme_evaluations', [
            'training_day_evaluation_id' => TrainingDayEvaluation::firstOrFail()->getKey(),
            'subject_matter_expert_id' => $expert->getKey(),
        ]);
    }

    public function test_a_second_submission_amends_the_first(): void
    {
        [$participant, , $registration, $expert] = $this->scenario();

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert, 5));

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert, 2));

        // One row, not two: the participant corrected their answer, they did
        // not vote a second time.
        $this->assertSame(1, TrainingDayEvaluation::count());
        $this->assertSame(1, SmeEvaluation::count());
        $this->assertSame(2, SmeEvaluation::firstOrFail()->knowledge_rating->value);
    }

    public function test_a_day_that_has_not_happened_cannot_be_evaluated(): void
    {
        [$participant, , $registration, $expert] = $this->scenario(3);

        // Day 1 is today; day 3 is the day after tomorrow.
        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/3", $this->answers($expert))
            ->assertSessionHasErrors('day');

        $this->assertSame(0, TrainingDayEvaluation::count());
    }

    public function test_a_day_outside_the_run_is_refused(): void
    {
        [$participant, , $registration, $expert] = $this->scenario();

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/9", $this->answers($expert))
            ->assertSessionHasErrors('day');
    }

    public function test_an_expert_who_was_not_on_that_day_cannot_be_rated(): void
    {
        [$participant, $training, $registration] = $this->scenario(2);

        // Two experts, one per day. Rating the day 2 expert on day 1 is the
        // stale-form case, and it must not be silently accepted.
        $dayOne = $training->subjectMatterExperts->first();
        $dayTwo = SubjectMatterExpert::factory()->create();

        $training->subjectMatterExperts()->sync([
            $dayOne->getKey() => ['days' => json_encode([1]), 'sort_order' => 0],
            $dayTwo->getKey() => ['days' => json_encode([2]), 'sort_order' => 1],
        ]);

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($dayTwo))
            ->assertSessionHasErrors('ratings');

        $this->assertSame(0, SmeEvaluation::count());
    }

    public function test_every_expert_on_the_day_must_be_rated(): void
    {
        [$participant, $training, $registration, $expert] = $this->scenario();

        $second = SubjectMatterExpert::factory()->create();
        $training->subjectMatterExperts()->attach($second, ['sort_order' => 1]);

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert))
            ->assertSessionHasErrors('ratings');
    }

    public function test_a_participant_cannot_evaluate_someone_elses_registration(): void
    {
        [, , $registration, $expert] = $this->scenario();
        $stranger = $this->participant();

        $this->actingAs($stranger)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get("/my/evaluations/{$registration->getKey()}/days/1")
            ->assertForbidden();
    }

    public function test_a_participant_marked_absent_cannot_evaluate_that_day(): void
    {
        [$participant, $training, $registration, $expert] = $this->scenario();

        Attendance::create([
            'registration_id' => $registration->getKey(),
            'training_day' => 1,
            'attendance_date' => $training->starts_at->toDateString(),
            'status' => AttendanceStatus::Absent,
        ]);

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert))
            ->assertSessionHasErrors('day');
    }

    public function test_a_cancelled_registration_cannot_evaluate(): void
    {
        [$participant, , $registration, $expert] = $this->scenario();

        $registration->update(['status' => RegistrationStatus::Cancelled]);

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert))
            ->assertSessionHasErrors('day');
    }

    /**
     * The carried-over session: one expert, two days, one evaluation.
     *
     * This is the rule the whole file turns on. A session that runs into the
     * next day is one session, so the room is asked about it once, at the end —
     * not on the evening of a day where it was still half-delivered.
     */
    public function test_an_expert_who_continues_tomorrow_is_evaluated_at_the_end(): void
    {
        [, $training, $registration] = $this->scenario(2);

        $days = SmeEvaluationService::daysFor($registration->refresh());

        $this->assertCount(0, $days[0]['experts']);
        $this->assertCount(1, $days[0]['continuing']);
        $this->assertFalse($days[0]['open']);
        $this->assertStringContainsString('end of day 2', $days[0]['reason']);

        $this->assertCount(1, $days[1]['experts']);
        $this->assertSame([2], $training->evaluationDays());
    }

    /** And posting the day it carried over from is refused, not quietly filed. */
    public function test_a_day_whose_session_continues_cannot_be_evaluated(): void
    {
        [$participant, , $registration, $expert] = $this->scenario(2);

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert))
            ->assertSessionHasErrors('day');

        $this->assertSame(0, TrainingDayEvaluation::count());
    }

    /**
     * Two stretches, two evaluations. An expert booked for days 1-2 and again
     * for day 4 delivered two separate things, and a verdict on the second is
     * not feedback on the first — so the gap breaks the run.
     */
    public function test_a_gap_in_an_experts_days_starts_a_second_evaluation(): void
    {
        [, $training, , $expert] = $this->scenario(4);

        $training->subjectMatterExperts()->sync([
            $expert->getKey() => ['days' => json_encode([1, 2, 4]), 'sort_order' => 0],
        ]);
        $training->refresh()->load('subjectMatterExperts');

        $this->assertSame([2, 4], $training->evaluationDaysForExpert($training->subjectMatterExperts->first()));
        $this->assertSame([2, 4], $training->evaluationDays());
        $this->assertSame([1, 2], $training->expertStretchAroundDay($training->subjectMatterExperts->first(), 1));
    }

    /** The form says what the rating covers, so day 1 is not answered for alone. */
    public function test_the_form_names_the_days_a_carried_over_rating_covers(): void
    {
        [$participant, $training, $registration] = $this->scenario(2);

        // Bring day 2 into the past so the form opens.
        $training->update([
            'starts_at' => now()->subDay(),
            'ends_at' => now(),
        ]);

        $this->actingAs($participant)
            ->get("/my/evaluations/{$registration->getKey()}/days/2")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Evaluations/Form')
                ->where('experts.0.days', [1, 2])
            );
    }

    public function test_a_day_with_no_expert_assigned_is_not_offered(): void
    {
        [$participant, $training, $registration] = $this->scenario();

        $training->subjectMatterExperts()->detach();

        $days = SmeEvaluationService::daysFor($registration->refresh());

        $this->assertFalse($days[0]['open']);
        $this->assertStringContainsString('No subject matter expert', $days[0]['reason']);
    }

    public function test_the_list_shows_what_is_still_owed(): void
    {
        [$participant, , $registration, $expert] = $this->scenario(2, [1]);

        $this->actingAs($participant)
            ->get('/my/evaluations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('My/Evaluations')
                // Day 1 has happened, day 2 has not.
                ->where('pending', 1)
            );

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert));

        $this->actingAs($participant)
            ->get('/my/evaluations')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pending', 0));
    }

    public function test_the_form_carries_the_criteria_and_the_scale(): void
    {
        [$participant, , $registration] = $this->scenario();

        $this->actingAs($participant)
            ->get("/my/evaluations/{$registration->getKey()}/days/1")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Evaluations/Form')
                ->has('experts', 1)
                ->has('criteria', 4)
                ->has('scale', 5)
                ->where('existing', null)
            );
    }

    public function test_hrd_reads_the_results_for_a_training(): void
    {
        [$participant, $training, $registration, $expert] = $this->scenario();

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert, 4));

        $this->actingAs($this->staff())
            ->get("/admin/trainings/{$training->getKey()}/evaluations")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Evaluations/Show')
                // A whole-number mean serialises as 4, not 4.0.
                ->where('results.experts.0.average', 4)
                ->where('results.experts.0.responses', 1)
                ->where('comments.0.experts.0.comments', 'Clear and patient.')
            );
    }

    /**
     * A field-office user is scoped to their own participants, and an average
     * over a subset would be quoted as the training's rating. So they are kept
     * out of the results screens entirely rather than shown a partial one.
     */
    public function test_a_field_office_user_cannot_read_the_results(): void
    {
        [, $training] = $this->scenario();

        $this->actingAs($this->staff(Role::FieldOffice))
            ->get("/admin/trainings/{$training->getKey()}/evaluations")
            ->assertForbidden();

        $this->actingAs($this->staff(Role::FieldOffice))
            ->get('/admin/evaluations')
            ->assertForbidden();
    }

    public function test_management_reads_results_but_not_the_expert_directory(): void
    {
        [, $training] = $this->scenario();

        $this->actingAs($this->staff(Role::Management))
            ->get('/admin/evaluations')
            ->assertOk();

        // The link to an expert's own record is withheld from a role that
        // cannot open it.
        $this->actingAs($this->staff(Role::Management))
            ->get("/admin/trainings/{$training->getKey()}/evaluations")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assignments.0.url', null));

        $this->actingAs($this->staff(Role::Management))
            ->get('/admin/smes')
            ->assertForbidden();
    }

    public function test_hrd_manages_the_expert_directory(): void
    {
        $this->actingAs($this->staff())
            ->post('/admin/smes', [
                'name' => 'LEILANI C. PAREL',
                'position' => 'Chief HR Specialist',
                'is_active' => true,
            ])
            ->assertRedirect('/admin/smes');

        $expert = SubjectMatterExpert::firstOrFail();

        $this->assertTrue($expert->is_active);
        $this->assertSame('Chief HR Specialist LEILANI C. PAREL', $expert->displayName());

        // Deactivation is the retirement path — the record and its history stay.
        $this->actingAs($this->staff())
            ->post("/admin/smes/{$expert->getKey()}/toggle");

        $this->assertFalse($expert->refresh()->is_active);
    }

    public function test_the_directory_and_an_experts_record_render_with_their_ratings(): void
    {
        [$participant, , $registration, $expert] = $this->scenario();

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert, 5));

        $this->actingAs($this->staff())
            ->get('/admin/smes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/SubjectMatterExperts/Index')
                ->where('experts.0.responses', 1)
                ->where('experts.0.average', 5)
                ->where('experts.0.trainings', 1)
            );

        $this->actingAs($this->staff())
            ->get("/admin/smes/{$expert->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/SubjectMatterExperts/Show')
                ->where('summary.responses', 1)
                ->where('summary.average', 5)
                ->has('summary.trainings', 1)
                ->has('assignments', 1)
            );
    }

    public function test_the_evaluations_index_reports_the_response_rate(): void
    {
        // Two experts, one finishing on each day, so both days collect a form.
        [$participant, $training, $registration, $expert] = $this->scenario(2, [1]);

        $training->subjectMatterExperts()->attach(
            SubjectMatterExpert::factory()->create(),
            ['sort_order' => 1, 'days' => json_encode([2])]
        );

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert));

        $this->actingAs($this->staff())
            ->get('/admin/evaluations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Evaluations/Index')
                ->where('trainings.0.id', $training->getKey())
                ->where('trainings.0.submissions', 1)
                // One participant across two days is two possible submissions.
                ->where('trainings.0.possible', 2)
                ->where('trainings.0.response_rate', 50)
            );
    }

    public function test_the_end_of_day_command_invites_todays_participants_once(): void
    {
        Notification::fake();

        [$participant, , , $expert] = $this->scenario(2, [1]);

        $this->artisan('tims:invite-evaluations')->assertSuccessful();

        Notification::assertSentTo(
            $participant,
            EvaluationRequested::class,
            // Day 1 is today. Day 2 has not happened, and inviting somebody to
            // evaluate a session they have not attended is the one thing this
            // command must never do.
            fn (EvaluationRequested $notification) => str_contains(
                $notification->body($participant),
                $expert->displayName()
            )
        );

        $this->assertDatabaseHas('evaluation_invitations', ['day_number' => 1]);
        $this->assertSame(1, EvaluationInvitation::count());

        // A second run — a retry, a hand-run after a failure — is quiet.
        $this->artisan('tims:invite-evaluations')->assertSuccessful();

        Notification::assertSentToTimes($participant, EvaluationRequested::class, 1);
        $this->assertSame(1, EvaluationInvitation::count());
    }

    public function test_the_command_skips_anyone_who_has_already_answered(): void
    {
        Notification::fake();

        [$participant, , $registration, $expert] = $this->scenario();

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert));

        $this->artisan('tims:invite-evaluations')->assertSuccessful();

        Notification::assertNothingSentTo($participant);
    }

    public function test_the_command_skips_someone_marked_absent(): void
    {
        Notification::fake();

        [$participant, $training, $registration] = $this->scenario();

        Attendance::create([
            'registration_id' => $registration->getKey(),
            'training_day' => 1,
            'attendance_date' => $training->starts_at->toDateString(),
            'status' => AttendanceStatus::Absent,
        ]);

        $this->artisan('tims:invite-evaluations')->assertSuccessful();

        Notification::assertNothingSentTo($participant);
    }

    public function test_the_invitation_links_to_that_days_form(): void
    {
        [$participant, , $registration] = $this->scenario();

        $this->artisan('tims:invite-evaluations')->assertSuccessful();

        $notification = $participant->refresh()->notifications()->firstOrFail();

        $this->assertSame(
            "/my/evaluations/{$registration->getKey()}/days/1",
            parse_url($notification->data['url'], PHP_URL_PATH)
        );
    }

    /** The chase column field offices work from, scoped like every roster row. */
    public function test_the_roster_reports_who_still_owes_an_evaluation(): void
    {
        [$participant, $training, $registration, $expert] = $this->scenario(2, [1]);

        $this->actingAs($this->staff())
            ->get("/admin/trainings/{$training->getKey()}/roster")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('training.collects_evaluations', true)
                // Day 1 has happened, day 2 has not: one answer is owed, not two.
                ->where('registrations.0.evaluation.expected', 1)
                ->where('registrations.0.evaluation.submitted', 0)
                ->where('registrations.0.evaluation.outstanding', [1])
                ->where('summary.evaluations_outstanding', 1)
            );

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert));

        $this->actingAs($this->staff())
            ->get("/admin/trainings/{$training->getKey()}/roster")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registrations.0.evaluation.submitted', 1)
                ->where('registrations.0.evaluation.outstanding', [])
                ->where('summary.evaluations_outstanding', 0)
            );
    }

    public function test_a_training_with_no_panel_drops_the_roster_column(): void
    {
        [, $training] = $this->scenario();

        $training->subjectMatterExperts()->detach();

        $this->actingAs($this->staff())
            ->get("/admin/trainings/{$training->getKey()}/roster")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('training.collects_evaluations', false)
                ->where('summary.evaluations_outstanding', 0)
            );
    }

    public function test_a_duplicate_expert_name_is_refused(): void
    {
        SubjectMatterExpert::factory()->create(['name' => 'LEILANI C. PAREL']);

        $this->actingAs($this->staff())
            ->post('/admin/smes', ['name' => 'LEILANI C. PAREL'])
            ->assertSessionHasErrors('name');
    }

    public function test_assigning_experts_on_the_training_form_records_the_panel(): void
    {
        $training = Training::factory()->runningFor(3)->create();
        $first = SubjectMatterExpert::factory()->create();
        $second = SubjectMatterExpert::factory()->create();

        $this->actingAs($this->staff())
            ->put("/admin/trainings/{$training->getKey()}", [
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $training->ends_at->format('Y-m-d\TH:i'),
                'duration_days' => 3,
                'status' => $training->status->value,
                'signatory_name' => 'THE REGIONAL DIRECTOR',
                'subject_matter_experts' => [
                    ['id' => $first->getKey(), 'topic' => 'Plenary', 'days' => [1, 2]],
                    // Day 9 does not exist on a three-day run and is dropped
                    // rather than rejected — see syncExperts().
                    ['id' => $second->getKey(), 'topic' => null, 'days' => [3, 9]],
                ],
            ])
            ->assertRedirect();

        $training->refresh()->load('subjectMatterExperts');

        $this->assertSame('THE REGIONAL DIRECTOR', $training->signatory_name);
        $this->assertCount(2, $training->subjectMatterExperts);
        $this->assertSame([1, 2], json_decode($training->subjectMatterExperts[0]->pivot->days, true));
        $this->assertSame([3], json_decode($training->subjectMatterExperts[1]->pivot->days, true));

        // And the day filter follows from it.
        $this->assertSame(
            [$first->getKey()],
            $training->expertsForDay(1)->pluck('id')->all()
        );
        $this->assertSame(
            [$second->getKey()],
            $training->expertsForDay(3)->pluck('id')->all()
        );
    }

    public function test_removing_an_expert_from_the_panel_keeps_filed_evaluations(): void
    {
        [$participant, $training, $registration, $expert] = $this->scenario();

        $this->actingAs($participant)
            ->post("/my/evaluations/{$registration->getKey()}/days/1", $this->answers($expert));

        $training->subjectMatterExperts()->detach();

        // The session happened and was evaluated; correcting the programme
        // afterwards does not unsay it.
        $this->assertSame(1, SmeEvaluation::count());
        $this->assertSame(1, SmeEvaluationService::summaryForExpert($expert->refresh())['responses']);
    }

    public function test_submitting_a_day_twice_concurrently_is_guarded_by_the_service(): void
    {
        [, , $registration, $expert] = $this->scenario();

        SmeEvaluationService::submit($registration, 1, [], [
            $expert->getKey() => [
                'knowledge_rating' => 4,
                'interaction_rating' => 4,
                'engagement_rating' => 4,
                'pace_rating' => 4,
            ],
        ]);

        $this->expectException(ValidationException::class);

        // Day 2 does not exist on a one-day run.
        SmeEvaluationService::submit($registration, 2, [], [
            $expert->getKey() => [
                'knowledge_rating' => 4,
                'interaction_rating' => 4,
                'engagement_rating' => 4,
                'pace_rating' => 4,
            ],
        ]);
    }
}
