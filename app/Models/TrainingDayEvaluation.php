<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One participant's evaluation of one training day.
 *
 * Holds the session-level answers — what they learned, what they liked, what
 * needs improving — and owns the per-expert ratings filed with it. The tuple
 * that identifies it is (registration, day), and the database enforces that:
 * re-opening the form edits this row rather than adding a second one.
 */
#[Fillable([
    'training_id', 'registration_id', 'day_number',
    'learned', 'liked_most', 'needs_improvement', 'suggestions', 'submitted_at',
])]
class TrainingDayEvaluation extends Model
{
    protected function casts(): array
    {
        return [
            'day_number' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function smeEvaluations(): HasMany
    {
        return $this->hasMany(SmeEvaluation::class);
    }

    /**
     * True when the participant wrote something beyond the ratings.
     *
     * The narrative answers are optional, and a summary screen that lists every
     * submission as a quotable comment when three-quarters of them are blank is
     * a screen nobody reads twice.
     */
    public function hasNarrative(): bool
    {
        return filled($this->learned)
            || filled($this->liked_most)
            || filled($this->needs_improvement)
            || filled($this->suggestions);
    }
}
