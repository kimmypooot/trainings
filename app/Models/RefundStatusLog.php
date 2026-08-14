<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per transition of a refund request.
 *
 * Append-only by convention — nothing in the app updates or deletes these.
 * `timestamps` are off because `changed_at` already is the timestamp, and a
 * separate created_at that could disagree with it would be a liability in the
 * one table whose entire purpose is being trustworthy.
 */
#[Fillable([
    'refund_request_id', 'from_status', 'to_status', 'notes', 'changed_by', 'changed_at',
])]
class RefundStatusLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_status' => RefundStatus::class,
            'to_status' => RefundStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
