<?php

namespace App\Models;

use Database\Factories\SubjectMatterExpertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A resource person the office assigns to deliver a training.
 *
 * Reference data, like a field office: created once, assigned many times, and
 * deactivated rather than deleted so the evaluations filed against the name
 * keep resolving. `is_active` only governs whether they can be newly assigned —
 * a run already carrying them is untouched.
 */
#[Fillable([
    'name', 'position', 'organization', 'email', 'contact_number',
    'expertise', 'bio', 'remarks', 'is_active', 'created_by',
])]
class SubjectMatterExpert extends Model
{
    /** @use HasFactory<SubjectMatterExpertFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The runs this expert is assigned to.
     *
     * Annotated because the bare `BelongsToMany` hint resolves its rows to the
     * base `Model`, so every read off one of them — `$training->title`,
     * `->starts_at` — looks undefined to static analysis, and a `map()` over
     * the collection is rejected on the callback's own parameter type.
     *
     * @return BelongsToMany<Training, $this>
     */
    public function trainings(): BelongsToMany
    {
        // Table named explicitly — see the matching relation on Training.
        return $this->belongsToMany(Training::class, 'training_subject_matter_expert')
            ->withPivot(['topic', 'days', 'sort_order'])
            ->withTimestamps()
            ->orderBy('training_subject_matter_expert.sort_order');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(SmeEvaluation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Name and position as a participant sees it on the evaluation form —
     * "Chief HR Specialist Leilani C. Parel", the way the Commission's own
     * form headings read.
     */
    public function displayName(): string
    {
        return trim(($this->position ? $this->position.' ' : '').$this->name);
    }

    /**
     * Options for the training form's picker.
     *
     * Active only, so a retired expert cannot be newly assigned. The training
     * form adds back any inactive expert the run already carries — see
     * TrainingController::formOptions() — because hiding someone who is on the
     * programme would make saving the form silently drop them.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return self::active()
            ->orderBy('name')
            ->get()
            ->map(fn (self $expert) => [
                'value' => $expert->getKey(),
                'label' => $expert->displayName(),
            ])
            ->all();
    }
}
