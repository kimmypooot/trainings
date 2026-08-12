<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_id', 'amount', 'reason', 'status', 'reviewed_by',
    'reviewed_at', 'review_remarks', 'refunded_at',
])]
class RefundRequest extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => RequestStatus::class,
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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
