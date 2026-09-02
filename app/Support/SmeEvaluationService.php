<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Enums\EvaluationRating;
use App\Enums\EvaluationScanOutcome;
use App\Enums\RegistrationStatus;
use App\Enums\TrainingStatus;
use App\Models\Attendance;
use App\Models\EvaluationDayCode;
use App\Models\EvaluationInvitation;
use App\Models\Registration;
use App\Models\SmeEvaluation;
use App\Models\SubjectMatterExpert;
use App\Models\Training;
use App\Models\TrainingDayEvaluation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * End-of-day evaluation of a training's subject matter experts.
 *
 * The unit of work is a *training day*, not a training: a participant rates the
 * experts they watched that day, on the evening of that day, while the session
 * is still fresh. An expert whose session carries on into the next day is the
 * exception — they are rated once, at the end of the last day they deliver, so
 * a two-day session is judged whole rather than twice in halves.
 *
 * Everything here therefore keys on the pair (registration, day number), and
 * the database carries a unique index on exactly that pair so a double submit
 * updates rather than duplicates.
 *
 * Three questions the rest of the app asks this class:
 *   - what can this participant evaluate right now (openDaysFor / pendingFor)
 *   - record what they said (submit)
 *   - what did the room think (resultsFor / summaryForExpert)
 *
 * Reading and writing share one gate — assertOpen() — so a day the participant
 * was never offered cannot be submitted by posting its number.
 */
class SmeEvaluationService
{
    /**
     * SQLSTATE for "you broke a constraint" — the class of error a lost
     * insert race reports as, on both MySQL and SQLite.
     */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /**
     * The training days this registration may evaluate, with what is known
     * about each: the experts on that day, and the submission if one exists.
     *
     * A day appears only once it has actually started. Evaluating a session
     * before it happens is the one thing this workflow must never allow, and
     * the check is a date comparison rather than an attendance lookup so that a
     * training run without the scanning station still collects feedback.
     *
     * There is deliberately no closing date on the other side. A participant
     * who went home to no signal on the last evening would otherwise lose their
     * say entirely, and the office would rather have a late evaluation than
     * none — `submitted_at` records how promptly each one arrived.
     *
     * Not every day of a run collects one. An expert who is back tomorrow is
     * rated at the end of their last day rather than each evening — see
     * Training::expertsEvaluatedOnDay() for why — so a day whose experts all
     * carry over has nothing to ask and is closed with a sentence saying where
     * the form went. On a run delivered by one expert throughout, that means a
     * single evaluation on the final day.
     *
     * @return array<int, array{
     *     day: int, date: CarbonImmutable, experts: Collection<int, SubjectMatterExpert>,
     *     continuing: Collection<int, SubjectMatterExpert>,
     *     evaluation: ?TrainingDayEvaluation, open: bool, reason: ?string
     * }>
     */
    public static function daysFor(Registration $registration): array
    {
        $training = $registration->training;
        $training->loadMissing('subjectMatterExperts');

        /*
         * Eager-loaded relations are used as they stand.
         *
         * Every caller that asks about more than one registration — the sidebar
         * badge, the roster column — loads `dayEvaluations` and `attendances`
         * up front, and re-querying them here would turn one query into two per
         * participant on a page that already has them in memory. A single
         * registration arriving without them still works: it falls through to
         * the query below.
         */
        $submitted = ($registration->relationLoaded('dayEvaluations')
            ? $registration->dayEvaluations
            : $registration->dayEvaluations()->get()
        )->keyBy('day_number');

        $attendance = ($registration->relationLoaded('attendances')
            ? $registration->attendances
            : $registration->attendances()->get()
        )->keyBy('training_day');

        $today = CarbonImmutable::now()->startOfDay();

        return collect($training->trainingDays())
            ->map(function (array $day) use ($training, $submitted, $attendance, $today, $registration) {
                $experts = $training->expertsEvaluatedOnDay($day['day']);

                // Present today, but back tomorrow: named so the participant's
                // list can still say who they saw on a day that asks nothing.
                $continuing = $training->expertsForDay($day['day'])
                    ->reject(fn (SubjectMatterExpert $expert) => $experts->contains('id', $expert->getKey()))
                    ->values();

                $reason = self::blockingReason(
                    $registration, $training, $day, $experts, $continuing, $attendance, $today
                );

                return [
                    'day' => $day['day'],
                    'date' => $day['date'],
                    'experts' => $experts,
                    'continuing' => $continuing,
                    'evaluation' => $submitted->get($day['day']),
                    'open' => $reason === null,
                    'reason' => $reason,
                ];
            })
            ->all();
    }

    /**
     * Why this day cannot be evaluated, or null when it can.
     *
     * Returned as a sentence rather than a boolean because every one of these
     * is something the participant is entitled to be told — a form that is
     * simply missing reads as a bug, and the office ends up fielding the call.
     *
     * @param  array{day: int, date: CarbonImmutable}  $day
     * @param  Collection<int, SubjectMatterExpert>  $experts  due to be rated tonight
     * @param  Collection<int, SubjectMatterExpert>  $continuing  present, but back tomorrow
     * @param  Collection<int, Attendance>  $attendance
     */
    private static function blockingReason(
        Registration $registration,
        Training $training,
        array $day,
        Collection $experts,
        Collection $continuing,
        Collection $attendance,
        CarbonImmutable $today,
    ): ?string {
        if (! $registration->status->occupiesSlot()) {
            return 'Only participants holding a slot on this training can evaluate it.';
        }

        if ($experts->isEmpty()) {
            /*
             * A carried-over session is not a misconfigured day, and telling
             * the participant that no expert is assigned — when they watched
             * one all afternoon — is how the office gets the phone call. Name
             * the day the form will appear on instead; the earliest one, since
             * that is the next thing that will be asked of them.
             */
            if ($continuing->isNotEmpty()) {
                $next = $continuing
                    ->map(fn (SubjectMatterExpert $expert) => $training->evaluationDayForExpert($expert, $day['day']))
                    ->filter()
                    ->min();

                return "This session continues on the next training day — you will evaluate it at the end of day {$next}.";
            }

            return 'No subject matter expert has been assigned to this day yet.';
        }

        if ($day['date']->greaterThan($today)) {
            return 'This session has not taken place yet.';
        }

        /*
         * Marked absent means marked absent by staff at the door, which is a
         * positive statement that this person was not in the room — so their
         * rating of the session is not theirs to give. A day with no attendance
         * record at all is not treated the same way: plenty of runs never scan
         * anyone, and inferring absence from silence would collect nothing.
         */
        $status = $attendance->get($day['day'])?->status;

        if ($status === AttendanceStatus::Absent) {
            return 'You were marked absent on this day.';
        }

        return null;
    }

    /**
     * Days this participant still owes an evaluation on, across every training
     * they hold a slot on. Drives the sidebar badge and the participant's
     * evaluation list.
     *
     * @return Collection<int, array{registration: Registration, day: int, date: CarbonImmutable}>
     */
    public static function pendingFor(User $user): Collection
    {
        $registrations = Registration::with(['training.subjectMatterExperts', 'dayEvaluations', 'attendances'])
            ->where('user_id', $user->getKey())
            ->whereIn('status', RegistrationStatus::occupying())
            // Nothing that has not begun can be owed an evaluation, and a
            // participant with a year of future bookings should not pay for
            // that in queries on every page load.
            ->whereHas('training', fn ($query) => $query->where('starts_at', '<=', now()))
            ->get();

        return $registrations->flatMap(
            fn (Registration $registration) => collect(self::daysFor($registration))
                ->filter(fn (array $day) => $day['open'] && $day['evaluation'] === null)
                ->map(fn (array $day) => [
                    'registration' => $registration,
                    'day' => $day['day'],
                    'date' => $day['date'],
                ])
        )->values();
    }

    /**
     * Who should be asked, this evening, to evaluate the day that has just
     * finished.
     *
     * Deliberately built on the same daysFor() rules the form itself applies,
     * so nobody is ever invited to a page that will then refuse them — an
     * invitation to somebody marked absent, or to a day with no expert on it,
     * is worse than no invitation at all.
     *
     * Already-invited pairs are excluded here as well as being barred by the
     * unique index, so a second run of the command is quiet rather than merely
     * harmless.
     *
     * @return Collection<int, array{
     *     registration: Registration, day: int, experts: Collection<int, SubjectMatterExpert>
     * }>
     */
    public static function invitationsDueOn(CarbonImmutable $date): Collection
    {
        $registrations = Registration::with([
            'user', 'training.subjectMatterExperts', 'dayEvaluations', 'attendances',
        ])
            ->whereIn('status', RegistrationStatus::occupying())
            ->whereHas('training', fn ($query) => $query
                ->where('starts_at', '<=', $date->endOfDay())
                ->where('status', TrainingStatus::Published)
            )
            ->get();

        // One lookup for the whole batch. The alternative — asking per
        // registration — is a query per participant on a command that runs
        // across every training running that day.
        $invited = EvaluationInvitation::whereIn('registration_id', $registrations->modelKeys())
            ->get()
            ->map(fn (EvaluationInvitation $invitation) => $invitation->registration_id.':'.$invitation->day_number)
            ->flip();

        return $registrations->flatMap(
            fn (Registration $registration) => collect(self::daysFor($registration))
                // The day that ended today, not every day still outstanding:
                // this is the end-of-session prompt, and a participant who
                // ignored day 1 should not be sent day 1 again alongside day 2.
                ->filter(fn (array $day) => $day['date']->isSameDay($date)
                    && $day['open']
                    && $day['evaluation'] === null
                    && ! $invited->has($registration->getKey().':'.$day['day'])
                )
                ->map(fn (array $day) => [
                    'registration' => $registration,
                    'day' => $day['day'],
                    'experts' => $day['experts'],
                ])
        )->values();
    }

    /**
     * How far along a registration is with its evaluations, for the roster.
     *
     * `expected` counts only the days actually open to this participant, so a
     * run still in progress, or somebody marked absent on day 2, is measured
     * against what they could have answered rather than against the length of
     * the programme.
     *
     * @return array{submitted: int, expected: int, outstanding: array<int, int>}
     */
    public static function progressFor(Registration $registration): array
    {
        $days = collect(self::daysFor($registration));

        $answerable = $days->filter(fn (array $day) => $day['open'] || $day['evaluation'] !== null);

        return [
            'submitted' => $answerable->filter(fn (array $day) => $day['evaluation'] !== null)->count(),
            'expected' => $answerable->count(),
            'outstanding' => $answerable
                ->filter(fn (array $day) => $day['evaluation'] === null)
                ->pluck('day')
                ->values()
                ->all(),
        ];
    }

    /**
     * The one day, resolved and checked, or a validation error naming the
     * reason. Every read and write of a single day's form goes through here.
     *
     * @return array{
     *     day: int, date: CarbonImmutable, experts: Collection<int, SubjectMatterExpert>,
     *     continuing: Collection<int, SubjectMatterExpert>,
     *     evaluation: ?TrainingDayEvaluation, open: bool, reason: ?string
     * }
     */
    public static function assertOpen(Registration $registration, int $day): array
    {
        $found = collect(self::daysFor($registration))->firstWhere('day', $day);

        if ($found === null) {
            throw ValidationException::withMessages([
                'day' => 'That day is not part of this training.',
            ]);
        }

        if (! $found['open']) {
            throw ValidationException::withMessages(['day' => $found['reason']]);
        }

        return $found;
    }

    /**
     * Every day of a run, and what a code for it would be worth.
     *
     * Built for the admin panel and the printable sheet, which must agree — one
     * of them saying a day collects a form while the other omits it is how a
     * poster ends up on a wall for a session nobody is being asked about.
     *
     * Lists *all* days, including the ones that collect nothing, and says why.
     * Omitting them would be the more obvious design and the wrong one: an
     * admin looking at a four-day course with one code cannot tell whether the
     * other three are missing or unnecessary, and the difference decides
     * whether they go looking for a bug.
     *
     * @return array<int, array{
     *     day: int, date: CarbonImmutable, collects: bool, rated_on: ?int,
     *     experts: Collection<int, SubjectMatterExpert>, submitted: int, expected: int
     * }>
     */
    public static function codeBoard(Training $training): array
    {
        $training->loadMissing('subjectMatterExperts');

        // One query for the whole run rather than one per day.
        $submitted = TrainingDayEvaluation::query()
            ->where('training_id', $training->getKey())
            ->toBase()
            ->selectRaw('day_number, count(*) as total')
            ->groupBy('day_number')
            ->pluck('total', 'day_number')
            ->all();

        // The same denominator resultsFor() uses: everyone holding a slot.
        $expected = $training->registrations()
            ->whereIn('status', RegistrationStatus::occupying())
            ->count();

        return array_map(function (array $day) use ($training, $submitted, $expected) {
            $experts = $training->expertsEvaluatedOnDay($day['day']);
            $carrying = $training->expertsForDay($day['day']);

            /*
             * Where this day's feedback actually gets collected. Null when the
             * day genuinely asks nothing — no panel on it at all — which reads
             * differently from "asked later" and is fixed differently too.
             */
            $ratedOn = $experts->isNotEmpty() || $carrying->isEmpty()
                ? null
                : $carrying
                    ->map(fn (SubjectMatterExpert $expert) => $training->evaluationDayForExpert($expert, $day['day']))
                    ->filter()
                    ->min();

            return [
                'day' => $day['day'],
                'date' => $day['date'],
                'collects' => $experts->isNotEmpty(),
                'rated_on' => $ratedOn,
                'experts' => $experts,
                'submitted' => (int) ($submitted[$day['day']] ?? 0),
                'expected' => $expected,
            ];
        }, $training->trainingDays());
    }

    /**
     * Who scanned a day's code, and what should happen to them.
     *
     * The second door onto this workflow. The first is the emailed invitation,
     * which arrives addressed to one registration and therefore needs to decide
     * nothing; a code on a wall is scanned by a whole room, including people who
     * are on a different course, people who wandered in, and people whose phone
     * was still logged in as their colleague's. So the identifying is done here.
     *
     * It lives in the service rather than the controller for the reason
     * ScanStationService gives about its own two doors: the moment a second
     * entrance derives eligibility for itself, the two answers start drifting,
     * and the drift is invisible until somebody is refused at one door and
     * admitted at the other. `daysFor()` remains the only thing that decides
     * whether a day is answerable, and this reads its verdict.
     *
     * Nothing about the code's own state is trusted beyond "is it revoked". In
     * particular, whether the day it names still collects a form is asked fresh:
     * the panel can be edited, and the run shortened, long after the poster went
     * on the wall.
     *
     * @return array{
     *     outcome: EvaluationScanOutcome, registration: ?Registration,
     *     day: ?int, reason: ?string
     * }
     */
    public static function resolveScan(User $user, EvaluationDayCode $code): array
    {
        $refuse = fn (EvaluationScanOutcome $outcome, ?string $reason = null) => [
            'outcome' => $outcome,
            'registration' => null,
            'day' => null,
            'reason' => $reason,
        ];

        if (! $code->isActive()) {
            return $refuse(EvaluationScanOutcome::Revoked);
        }

        /*
         * The roster is the identity check, and an occupying status is what
         * counts as being on it — the same set that may evaluate at all. A
         * withdrawn or rejected registration is treated as not registered
         * rather than as blocked, because "you cancelled this booking" is a
         * different sentence from "the form is not open yet", and conflating
         * them sends somebody to look for a form that will never appear.
         */
        $registration = Registration::with(['training.subjectMatterExperts', 'dayEvaluations', 'attendances'])
            ->where('user_id', $user->getKey())
            ->where('training_id', $code->training_id)
            ->whereIn('status', RegistrationStatus::occupying())
            ->first();

        if ($registration === null) {
            return $refuse(EvaluationScanOutcome::NotRegistered);
        }

        $day = collect(self::daysFor($registration))->firstWhere('day', $code->day_number);

        if ($day === null) {
            return $refuse(EvaluationScanOutcome::NoLongerScheduled);
        }

        $found = fn (EvaluationScanOutcome $outcome, ?string $reason = null) => [
            'outcome' => $outcome,
            'registration' => $registration,
            'day' => $day['day'],
            'reason' => $reason,
        ];

        /*
         * Answered wins over blocked, matching EvaluationController::show():
         * a participant marked absent after they had already filed their form
         * can still open it, and being shown "you were marked absent" over the
         * top of words they have already written would read as those words
         * having been thrown away.
         */
        if ($day['evaluation'] !== null) {
            return $found(EvaluationScanOutcome::Submitted);
        }

        if (! $day['open']) {
            return $found(EvaluationScanOutcome::Blocked, $day['reason']);
        }

        return $found(EvaluationScanOutcome::Open);
    }

    /**
     * File (or amend) one participant's evaluation of one training day.
     *
     * Ratings arrive keyed by expert id. Experts not up for evaluation on the
     * day are rejected rather than ignored: a payload naming somebody who was
     * not there — or whose session has not finished yet — is either a stale form
     * or a probe, and silently dropping it would leave the participant believing
     * they rated someone they did not.
     *
     * The whole submission is one transaction — a day whose narrative saved but
     * whose ratings did not would count as submitted and never be offered
     * again, losing the part that matters most.
     *
     * @param  array<string, mixed>  $data  narrative answers
     * @param  array<int, array<string, int|string|null>>  $ratings  expert id => criteria + comments
     */
    public static function submit(
        Registration $registration,
        int $day,
        array $data,
        array $ratings,
    ): TrainingDayEvaluation {
        $context = self::assertOpen($registration, $day);
        $allowed = $context['experts']->keyBy('id');

        $unknown = array_diff(array_keys($ratings), $allowed->keys()->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'ratings' => 'One of the experts on this form is not being evaluated on this training day.',
            ]);
        }

        // Every expert due tonight must be rated. A partially completed form
        // is the participant skipping the person they had least to say about,
        // which is exactly the rating the office needs.
        $missing = $allowed->keys()->diff(array_keys($ratings));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'ratings' => 'Please rate every subject matter expert on this form.',
            ]);
        }

        $persist = function () use ($registration, $day, $data, $ratings) {
            /*
             * updateOrCreate against the (registration, day) unique index, so
             * a participant who reopens the form corrects their answers instead
             * of filing a second, contradictory set. submitted_at moves with
             * the amendment — the office wants to know when the words it is
             * reading were written.
             */
            $evaluation = TrainingDayEvaluation::updateOrCreate(
                [
                    'registration_id' => $registration->getKey(),
                    'day_number' => $day,
                ],
                [
                    'training_id' => $registration->training_id,
                    'learned' => $data['learned'] ?? null,
                    'liked_most' => $data['liked_most'] ?? null,
                    'needs_improvement' => $data['needs_improvement'] ?? null,
                    'suggestions' => $data['suggestions'] ?? null,
                    'submitted_at' => now(),
                ]
            );

            foreach ($ratings as $expertId => $answers) {
                SmeEvaluation::updateOrCreate(
                    [
                        'training_day_evaluation_id' => $evaluation->getKey(),
                        'subject_matter_expert_id' => (int) $expertId,
                    ],
                    [
                        ...collect(array_keys(SmeEvaluation::CRITERIA))
                            ->mapWithKeys(fn (string $column) => [$column => (int) $answers[$column]])
                            ->all(),
                        'comments' => $answers['comments'] ?? null,
                    ]
                );
            }

            return $evaluation;
        };

        /*
         * Retried once on a duplicate key, and only on a duplicate key.
         *
         * updateOrCreate is read-then-write: two requests that both find no row
         * both try to insert one, and the (registration, day_number) unique
         * index — correctly — refuses the second. That used to surface as a 500
         * on a form the participant had filled in properly. It is not
         * hypothetical now that a code on a wall invites a whole room to open
         * the same form at once, and the same person double-tapping Submit on a
         * slow venue connection is the likeliest version of it.
         *
         * The retry is safe precisely because the operation is idempotent: the
         * second attempt finds the row the first one lost the race to and
         * updates it, which is what the participant asked for either way. Any
         * other database error is rethrown untouched — a retry loop that
         * swallows the difference between "somebody beat me to it" and "the
         * column is the wrong type" is how a schema bug becomes a silent one.
         */
        try {
            $evaluation = DB::transaction($persist);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== self::SQLSTATE_INTEGRITY_VIOLATION) {
                throw $exception;
            }

            $evaluation = DB::transaction($persist);
        }

        /*
         * Logged without the ratings themselves. The audit trail answers "did
         * this get filed, and when" — putting the scores in it would turn a log
         * that superadmins read casually into a second, unaggregated copy of
         * feedback the participant gave on the understanding that it would be
         * read as part of a summary.
         */
        ActivityLogger::record(
            'evaluation.submitted',
            $evaluation,
            "Day {$day} evaluation submitted for “{$registration->training->title}”.",
            ['training_id' => $registration->training_id, 'day' => $day],
            $registration->user,
        );

        return $evaluation;
    }

    /**
     * What the room thought of a run, per expert per day.
     *
     * Averaged in SQL — a mature training has hundreds of rows and this screen
     * shows a dozen numbers. Response *rate* is computed alongside the scores
     * because an average of 4.9 from two people out of forty is a different
     * fact from the same average out of thirty-eight, and a results page that
     * shows only the first is one people will misread.
     *
     * @return array<string, mixed>
     */
    public static function resultsFor(Training $training): array
    {
        $training->loadMissing('subjectMatterExperts');

        $criteria = array_keys(SmeEvaluation::CRITERIA);

        $rows = DB::table('sme_evaluations')
            ->join(
                'training_day_evaluations',
                'training_day_evaluations.id',
                '=',
                'sme_evaluations.training_day_evaluation_id'
            )
            ->where('training_day_evaluations.training_id', $training->getKey())
            ->groupBy('sme_evaluations.subject_matter_expert_id', 'training_day_evaluations.day_number')
            ->select([
                'sme_evaluations.subject_matter_expert_id as expert_id',
                'training_day_evaluations.day_number as day',
                DB::raw('COUNT(*) as responses'),
                ...array_map(
                    fn (string $column) => DB::raw("AVG(sme_evaluations.{$column}) as {$column}"),
                    $criteria
                ),
            ])
            ->get();

        // Slots held on the run, which is the denominator a coordinator means
        // by "how many replied" — not everyone ever registered.
        $expected = $training->registrations()
            ->whereIn('status', RegistrationStatus::occupying())
            ->count();

        $experts = $training->subjectMatterExperts->keyBy('id');

        $byExpert = $rows->groupBy('expert_id')->map(function (Collection $expertRows, $expertId) use ($experts, $criteria, $expected) {
            $expert = $experts->get($expertId);

            $days = $expertRows->map(fn ($row) => [
                'day' => (int) $row->day,
                'responses' => (int) $row->responses,
                'response_rate' => $expected > 0 ? round($row->responses / $expected * 100) : null,
                'criteria' => collect($criteria)
                    ->mapWithKeys(fn (string $column) => [$column => round((float) $row->{$column}, 2)])
                    ->all(),
                'average' => round(
                    collect($criteria)->sum(fn (string $column) => (float) $row->{$column}) / count($criteria),
                    2
                ),
            ])->sortBy('day')->values()->all();

            $responses = array_sum(array_column($days, 'responses'));

            return [
                'expert_id' => (int) $expertId,
                'name' => $expert?->name ?? 'Removed expert',
                'position' => $expert?->position,
                'responses' => $responses,
                // Weighted by responses so a day with three replies does not
                // count as much as a day with thirty.
                'average' => $responses > 0
                    ? round(
                        collect($days)->sum(fn (array $day) => $day['average'] * $day['responses']) / $responses,
                        2
                    )
                    : null,
                'days' => $days,
            ];
        })->sortByDesc('responses')->values()->all();

        return [
            'expected_responses' => $expected,
            'submissions' => TrainingDayEvaluation::where('training_id', $training->getKey())->count(),
            'experts' => $byExpert,
            // Experts on the programme nobody has rated yet. Listed explicitly
            // rather than left out, so an empty column reads as "no responses"
            // rather than "not assigned".
            'unrated' => $training->subjectMatterExperts
                ->reject(fn (SubjectMatterExpert $expert) => $rows->contains('expert_id', $expert->getKey()))
                ->map(fn (SubjectMatterExpert $expert) => [
                    'expert_id' => $expert->getKey(),
                    'name' => $expert->name,
                    'position' => $expert->position,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The narrative answers for a run, newest first.
     *
     * Attributed to the participant, and deliberately not scoped by field
     * office: the results screens are region-wide by design and the routes
     * withhold them from the one role that must not see the whole region (see
     * the evaluation route group). Anonymising feedback that names people in
     * its own text would protect nobody while making follow-up impossible.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function commentsFor(Training $training): array
    {
        return TrainingDayEvaluation::with([
            'registration.user.profile',
            'smeEvaluations.expert',
        ])
            ->where('training_id', $training->getKey())
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (TrainingDayEvaluation $evaluation) => [
                'id' => $evaluation->getKey(),
                'day' => $evaluation->day_number,
                'participant' => $evaluation->registration->user->name,
                'submitted_at' => $evaluation->submitted_at->format('d M Y, g:i A'),
                'learned' => $evaluation->learned,
                'liked_most' => $evaluation->liked_most,
                'needs_improvement' => $evaluation->needs_improvement,
                'suggestions' => $evaluation->suggestions,
                'experts' => $evaluation->smeEvaluations
                    ->map(fn (SmeEvaluation $rating) => [
                        'name' => $rating->expert?->name ?? 'Removed expert',
                        'average' => $rating->averageRating(),
                        'comments' => $rating->comments,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * One expert's standing across every run they have delivered.
     *
     * @return array<string, mixed>
     */
    public static function summaryForExpert(SubjectMatterExpert $expert): array
    {
        $criteria = array_keys(SmeEvaluation::CRITERIA);

        $overall = DB::table('sme_evaluations')
            ->where('subject_matter_expert_id', $expert->getKey())
            ->select([
                DB::raw('COUNT(*) as responses'),
                ...array_map(fn (string $column) => DB::raw("AVG({$column}) as {$column}"), $criteria),
            ])
            ->first();

        $perTraining = DB::table('sme_evaluations')
            ->join(
                'training_day_evaluations',
                'training_day_evaluations.id',
                '=',
                'sme_evaluations.training_day_evaluation_id'
            )
            ->join('trainings', 'trainings.id', '=', 'training_day_evaluations.training_id')
            ->where('sme_evaluations.subject_matter_expert_id', $expert->getKey())
            ->groupBy('trainings.id', 'trainings.title', 'trainings.starts_at')
            ->orderByDesc('trainings.starts_at')
            ->select([
                'trainings.id',
                'trainings.title',
                'trainings.starts_at',
                DB::raw('COUNT(*) as responses'),
                ...array_map(
                    fn (string $column) => DB::raw("AVG(sme_evaluations.{$column}) as {$column}"),
                    $criteria
                ),
            ])
            ->get();

        $mean = fn (object $row) => (int) $row->responses === 0
            ? null
            : round(collect($criteria)->sum(fn (string $column) => (float) $row->{$column}) / count($criteria), 2);

        return [
            'responses' => (int) ($overall->responses ?? 0),
            'average' => $overall && (int) $overall->responses > 0 ? $mean($overall) : null,
            'criteria' => $overall && (int) $overall->responses > 0
                ? collect($criteria)
                    ->mapWithKeys(fn (string $column) => [$column => round((float) $overall->{$column}, 2)])
                    ->all()
                : [],
            'trainings' => $perTraining->map(fn (object $row) => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'starts_at' => CarbonImmutable::parse($row->starts_at)->format('d M Y'),
                'responses' => (int) $row->responses,
                'average' => $mean($row),
            ])->all(),
        ];
    }

    /**
     * The rating scale and the questions, as the participant's form and the
     * results screens both need them. One source, so a reworded criterion does
     * not have to be chased through the templates.
     *
     * @return array<string, mixed>
     */
    public static function formDefinition(): array
    {
        return [
            'scale' => EvaluationRating::options(),
            'criteria' => collect(SmeEvaluation::CRITERIA)
                ->map(fn (string $statement, string $column) => [
                    'key' => $column,
                    'statement' => $statement,
                    'short' => SmeEvaluation::CRITERIA_SHORT[$column],
                ])
                ->values()
                ->all(),
        ];
    }
}
