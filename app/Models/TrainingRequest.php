<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Database\Factories\TrainingRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agency asking CSC to run a training, ported from v1's
 * `request-training.php` / `agency-requested.php`.
 */
#[Fillable([
    'requested_by', 'title', 'justification', 'category', 'expected_participants',
    'preferred_start', 'preferred_end', 'status', 'reviewed_by', 'reviewed_at',
    'review_remarks', 'training_id',
])]
class TrainingRequest extends Model
{
    /** @use HasFactory<TrainingRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'preferred_start' => 'date',
            'preferred_end' => 'date',
            'reviewed_at' => 'datetime',
            'expected_participants' => 'integer',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The training HRD created from this request, once approved. */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Pending);
    }
}
