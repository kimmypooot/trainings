<?php

namespace App\Models;

use App\Enums\EvaluationRating;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one participant rated one expert on one training day.
 *
 * The four criteria are the Commission's, kept in the order the paper form
 * asks them. CRITERIA is the single list every screen reads — the form renders
 * it, the results page averages it, and the export names its columns from it —
 * so adding a fifth question is a change here plus a migration, not a hunt
 * through four templates.
 */
#[Fillable([
    'training_day_evaluation_id', 'subject_matter_expert_id',
    'knowledge_rating', 'interaction_rating', 'engagement_rating', 'pace_rating', 'comments',
])]
class SmeEvaluation extends Model
{
    /**
     * Column => the statement the participant is agreeing or disagreeing with.
     *
     * @var array<string, string>
     */
    public const CRITERIA = [
        'knowledge_rating' => 'My knowledge about the topic has improved because of the expertise of the SME',
        'interaction_rating' => 'The SME encouraged my interaction and participation',
        'engagement_rating' => 'The style of the SME kept me engaged throughout the session',
        'pace_rating' => 'The SME discussed the topics based on my learning pace',
    ];

    /** A short label for table headers, where the full statement will not fit. */
    public const CRITERIA_SHORT = [
        'knowledge_rating' => 'Expertise',
        'interaction_rating' => 'Participation',
        'engagement_rating' => 'Engagement',
        'pace_rating' => 'Pacing',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_rating' => EvaluationRating::class,
            'interaction_rating' => EvaluationRating::class,
            'engagement_rating' => EvaluationRating::class,
            'pace_rating' => EvaluationRating::class,
        ];
    }

    public function dayEvaluation(): BelongsTo
    {
        return $this->belongsTo(TrainingDayEvaluation::class, 'training_day_evaluation_id');
    }

    public function expert(): BelongsTo
    {
        return $this->belongsTo(SubjectMatterExpert::class, 'subject_matter_expert_id');
    }

    /** This participant's mean across the four criteria, for one expert on one day. */
    public function averageRating(): float
    {
        $ratings = array_map(
            fn (string $column) => $this->{$column}->value,
            array_keys(self::CRITERIA)
        );

        return round(array_sum($ratings) / count($ratings), 2);
    }
}
