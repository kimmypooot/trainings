<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Enums\TrainingLevel;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TrainingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What `casts()` below already does, said in a form Larastan can read.
 *
 * Larastan takes a model's casts from the `$casts` *property*; this codebase
 * uses the `casts()` *method*, so every cast column resolves to its raw
 * database type instead. On this model that made `starts_at` a string, and so
 * every `->format()`, `->diffForHumans()` and date comparison on a training —
 * the roster header, the catalogue, the certificates, the dashboard — read as
 * a method call on a string. The same fix was already applied to User,
 * ScanLink, Registration, Profile and ActivityLog for the same reason.
 *
 * This is a statement of existing behaviour, not a suppression: a genuinely
 * wrong call on one of these still fails.
 *
 * Dates are `Carbon`, not `CarbonImmutable`: these are plain `datetime`
 * casts and nothing in this application swaps Laravel's default date class.
 * ScanLink's block says `CarbonImmutable` because its columns are cast
 * `immutable_datetime` — the docblock has to follow the cast, not the import
 * list.
 *
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $registration_opens_at
 * @property Carbon|null $registration_closes_at
 * @property int|null $duration_days
 * @property bool $payment_required
 * @property bool $accepts_promissory
 * @property bool $accepts_walk_ins
 * @property bool $is_supervisory
 * @property TrainingMode $mode
 * @property TrainingLevel|null $level
 * @property TrainingStatus $status
 */
#[Fillable([
    'title', 'slug', 'training_code', 'description', 'category', 'level', 'venue',
    'venue_details', 'meeting_link', 'mode', 'starts_at', 'ends_at', 'duration_days',
    'registration_opens_at', 'registration_closes_at', 'capacity', 'signatory_name',
    'prerequisites', 'target_participants',
    'payment_required', 'payment_amount', 'accepts_promissory', 'accepts_walk_ins', 'is_supervisory',
    'status', 'created_by', 'rescheduled_from_training_id',
])]
class Training extends Model
{
    /** @use HasFactory<TrainingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'duration_days' => 'integer',
            'payment_required' => 'boolean',
            'payment_amount' => 'decimal:2',
            'accepts_promissory' => 'boolean',
            'accepts_walk_ins' => 'boolean',
            'is_supervisory' => 'boolean',
            'mode' => TrainingMode::class,
            'level' => TrainingLevel::class,
            'status' => TrainingStatus::class,
        ];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /** Shareable scanning stations issued for this training's doors. */
    public function scanLinks(): HasMany
    {
        return $this->hasMany(ScanLink::class);
    }

    /**
     * The scannable posters for this run's evaluation days.
     *
     * At most one per day — see the unique index — and only for days that
     * collect a form, which is `evaluationDays()` and usually fewer than there
     * are days in the run.
     */
    public function evaluationDayCodes(): HasMany
    {
        return $this->hasMany(EvaluationDayCode::class);
    }

    /**
     * The resource persons delivering this run, in the order HRD arranged them.
     *
     * The pivot's `days` narrows an expert to particular training days; null
     * means every day. Read it through expertsForDay() rather than directly,
     * so the null-means-all rule lives in one place.
     */
    public function subjectMatterExperts(): BelongsToMany
    {
        // The pivot is named for how it reads — training, then its experts —
        // rather than for Laravel's alphabetical convention
        // (subject_matter_expert_training), so both ends name it explicitly.
        return $this->belongsToMany(SubjectMatterExpert::class, 'training_subject_matter_expert')
            ->withPivot(['topic', 'days', 'sort_order'])
            ->withTimestamps()
            ->orderBy('training_subject_matter_expert.sort_order')
            ->orderBy('subject_matter_experts.name');
    }

    /** Participant evaluations filed against this run, one per participant per day. */
    public function dayEvaluations(): HasMany
    {
        return $this->hasMany(TrainingDayEvaluation::class);
    }

    /**
     * The day numbers one assignment covers, normalised and clamped to the run.
     *
     * The pivot stores raw JSON — there is no cast on an anonymous pivot — and
     * null for "the whole run", so every reader would otherwise repeat the same
     * decode-and-default dance and one of them would eventually get it wrong.
     * Clamping matters because a run shortened after the experts were arranged
     * leaves behind day numbers that no longer exist.
     *
     * @return array<int, int>
     */
    public function daysForExpert(SubjectMatterExpert $expert): array
    {
        $all = range(1, max(1, $this->duration_days ?? 1));

        $days = $expert->pivot->days;

        if (is_string($days)) {
            $days = json_decode($days, true);
        }

        if (blank($days)) {
            return $all;
        }

        return array_values(array_intersect($all, array_map('intval', (array) $days)));
    }

    /**
     * The experts who were in the room on the given day.
     *
     * An assignment with no days listed covers the whole run — that is the
     * common case, and requiring HRD to tick every day for a single-expert
     * training would be busywork that goes wrong silently when someone forgets.
     *
     * "Who was in the room" is not the same question as "who is rated tonight";
     * for that, see expertsEvaluatedOnDay().
     *
     * @return Collection<int, SubjectMatterExpert>
     */
    public function expertsForDay(int $day): Collection
    {
        return $this->subjectMatterExperts
            ->filter(fn (SubjectMatterExpert $expert) => in_array($day, $this->daysForExpert($expert), true))
            ->values();
    }

    /**
     * The experts whose session *finishes* on the given day — the ones the
     * participant is asked to rate that evening.
     *
     * An expert who is back tomorrow is not evaluated tonight. A session that
     * runs across days 1 and 2 is one session, and rating it twice asks the
     * room to judge half a thing and then judge it again: the day-1 scores are
     * a verdict on something unfinished, and averaging them into day 2 buries
     * the finished one. So an expert is rated once per unbroken stretch of days
     * they are present for, on the last day of that stretch — which for the
     * ordinary whole-run assignment means once, at the end of the run.
     *
     * Unbroken stretch rather than simply "their last day": an expert booked
     * for days 1-2 and again for day 4 delivered two separate things, and a
     * verdict on the second is not feedback on the first.
     *
     * @return Collection<int, SubjectMatterExpert>
     */
    public function expertsEvaluatedOnDay(int $day): Collection
    {
        return $this->subjectMatterExperts
            ->filter(fn (SubjectMatterExpert $expert) => in_array(
                $day,
                $this->evaluationDaysForExpert($expert),
                true
            ))
            ->values();
    }

    /**
     * The days one assignment is rated on: the last day of each unbroken
     * stretch it covers.
     *
     * @return array<int, int>
     */
    public function evaluationDaysForExpert(SubjectMatterExpert $expert): array
    {
        $days = $this->daysForExpert($expert);

        return array_values(array_filter(
            $days,
            fn (int $day) => ! in_array($day + 1, $days, true)
        ));
    }

    /**
     * The unbroken stretch of days this expert's day-$day session runs over —
     * what one evaluation of it actually covers. Empty when they were not there.
     *
     * @return array<int, int>
     */
    public function expertStretchAroundDay(SubjectMatterExpert $expert, int $day): array
    {
        $days = $this->daysForExpert($expert);

        if (! in_array($day, $days, true)) {
            return [];
        }

        $from = $day;
        $to = $day;

        while (in_array($from - 1, $days, true)) {
            $from--;
        }

        while (in_array($to + 1, $days, true)) {
            $to++;
        }

        return range($from, $to);
    }

    /**
     * Where the evaluation of this expert's day-$day session lands: the end of
     * the stretch it belongs to. Null when they were not there at all.
     */
    public function evaluationDayForExpert(SubjectMatterExpert $expert, int $day): ?int
    {
        $stretch = $this->expertStretchAroundDay($expert, $day);

        return $stretch === [] ? null : end($stretch);
    }

    /**
     * The day numbers of this run that actually collect an evaluation.
     *
     * Fewer than `duration_days` whenever a session carries over: day 1 of a
     * two-day run delivered by one expert asks nothing of anybody. Response
     * rates divide by this rather than by the length of the programme, or every
     * continued run reports half the response rate it earned.
     *
     * @return array<int, int>
     */
    public function evaluationDays(): array
    {
        $this->loadMissing('subjectMatterExperts');

        return array_values(array_filter(
            range(1, max(1, $this->duration_days ?? 1)),
            fn (int $day) => $this->expertsEvaluatedOnDay($day)->isNotEmpty()
        ));
    }

    /**
     * The run this one was published to replace, if it replaces one.
     *
     * Null on almost every training. Present only where the office rescheduled:
     * the old run keeps its own record and its own history, and this points
     * back at it so the affected roster can be generated from the source rather
     * than from somebody's memory of who was on it.
     */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_training_id');
    }

    /**
     * Runs published to replace this one.
     *
     * Plural on purpose. A run cancelled for low turnout is sometimes split
     * across two later dates rather than moved wholesale to one, and the
     * participants are divided between them.
     */
    public function reschedules(): HasMany
    {
        return $this->hasMany(self::class, 'rescheduled_from_training_id');
    }

    /** Registrations that occupy a slot. */
    public function activeRegistrations(): HasMany
    {
        return $this->registrations()->whereIn('status', RegistrationStatus::occupying());
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', TrainingStatus::Published);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now());
    }

    /**
     * Everything that has not finished yet, including a run already in progress.
     *
     * upcoming() drops a multi-day program the moment its first morning starts,
     * which is wrong for any public listing: a three-day course should stay
     * announced while it is actually being delivered. A null ends_at means a
     * single-day run, so starts_at is the end for that purpose.
     */
    public function scopeNotEnded(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('ends_at', '>=', now())
                ->orWhere(fn (Builder $q) => $q->whereNull('ends_at')->where('starts_at', '>=', now()));
        });
    }

    public function isFull(): bool
    {
        if ($this->capacity === null) {
            return false;
        }

        return $this->activeRegistrations()->count() >= $this->capacity;
    }

    public function registrationHasClosed(): bool
    {
        $deadline = $this->registration_closes_at ?? $this->starts_at;

        return $deadline->isPast();
    }

    public function slotsRemaining(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, $this->capacity - $this->activeRegistrations()->count());
    }

    /**
     * Registration opens on the given date, or immediately when unset.
     *
     * Paired with registrationHasClosed(): both must pass before a participant
     * may register.
     */
    public function registrationHasOpened(): bool
    {
        return $this->registration_opens_at === null || $this->registration_opens_at->isPast();
    }

    /**
     * The run's dates, as one human string.
     *
     * "12 March 2026" for a single day, "12–14 March 2026" inside one month,
     * "28 February – 2 March 2026" across a boundary. The month is never
     * repeated when both ends share it — "12 March – 14 March 2026" is how a
     * date range looks when a machine wrote it.
     *
     * Lives here rather than in whichever screen happens to need it, because
     * three screens do: the mail templates' {{training_dates}} tag, the
     * analytics report header, and the training picker that feeds it. It was
     * private to EmailTemplateRenderer until the reports needed the same
     * string, and a second copy would have drifted from the one participants
     * see in their confirmation email — which is the one that matters.
     *
     * Note this is the *span*, not the session count: a training running three
     * Fridays spans a month and has duration_days of 3. Use trainingDays()
     * when the question is "which days does attendance get recorded on".
     */
    public function dateRange(): string
    {
        if ($this->starts_at === null) {
            return '';
        }

        $starts = $this->starts_at;
        $ends = $this->ends_at;

        if ($ends === null || $starts->isSameDay($ends)) {
            return $starts->format('d F Y');
        }

        return $starts->isSameMonth($ends)
            ? $starts->format('d').'–'.$ends->format('d F Y')
            : $starts->format('d F').' – '.$ends->format('d F Y');
    }

    /**
     * The calendar days this training runs, numbered from 1.
     *
     * Attendance is recorded per day, and the day number is what makes a scan
     * idempotent — so the numbering has to come from one place. Derived from
     * starts_at and duration_days rather than the starts_at/ends_at span, so
     * that a multi-week training with a fixed number of session days stays
     * correct.
     *
     * @return array<int, array{day: int, date: CarbonImmutable}>
     */
    public function trainingDays(): array
    {
        $start = CarbonImmutable::parse($this->starts_at)->startOfDay();

        return array_map(
            fn (int $offset) => [
                'day' => $offset + 1,
                'date' => $start->addDays($offset),
            ],
            range(0, max(1, $this->duration_days ?? 1) - 1)
        );
    }

    /**
     * Which training day a date falls on, or null when it falls outside the run.
     *
     * This is the guard that stops a scan on the wrong day from silently
     * recording attendance against day 1.
     */
    public function dayNumberFor(\DateTimeInterface $date): ?int
    {
        $target = CarbonImmutable::parse($date)->startOfDay();

        foreach ($this->trainingDays() as $day) {
            if ($day['date']->isSameDay($target)) {
                return $day['day'];
            }
        }

        return null;
    }

    /** True when today is one of the training's days. */
    public function isRunningToday(): bool
    {
        return $this->dayNumberFor(now()) !== null;
    }
}
