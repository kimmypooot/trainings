<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TrainingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'slug', 'training_code', 'description', 'category', 'venue', 'mode',
    'starts_at', 'ends_at', 'duration_days', 'registration_opens_at',
    'registration_closes_at', 'capacity', 'facilitator_name', 'facilitator_contact',
    'objectives', 'prerequisites', 'target_participants', 'payment_required',
    'payment_amount', 'status', 'created_by',
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
            'mode' => TrainingMode::class,
            'status' => TrainingStatus::class,
        ];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
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
