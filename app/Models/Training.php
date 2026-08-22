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
use Illuminate\Support\Collection;

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
     * The experts a participant on the given day is in a position to rate.
     *
     * An assignment with no days listed covers the whole run — that is the
     * common case, and requiring HRD to tick every day for a single-expert
     * training would be busywork that goes wrong silently when someone forgets.
     *
     * @return Collection<int, SubjectMatterExpert>
     */
    public function expertsForDay(int $day): Collection
    {
        return $this->subjectMatterExperts
            ->filter(function (SubjectMatterExpert $expert) use ($day) {
                $days = $expert->pivot->days;

                if (is_string($days)) {
                    $days = json_decode($days, true);
                }

                return blank($days) || in_array($day, array_map('intval', $days), true);
            })
            ->values();
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
