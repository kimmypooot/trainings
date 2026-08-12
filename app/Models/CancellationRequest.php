<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Database\Factories\CancellationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'registration_id', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_remarks',
])]
class CancellationRequest extends Model
{
    /** @use HasFactory<CancellationRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Pending);
    }
}
