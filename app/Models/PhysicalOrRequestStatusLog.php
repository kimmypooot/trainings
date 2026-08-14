<?php

namespace App\Models;

use App\Enums\PhysicalOrRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per transition of a physical OR request.
 *
 * Append-only by convention — nothing in the app updates or deletes these.
 * `timestamps` are off because `changed_at` already is the timestamp, and a
 * separate created_at that could disagree with it would be a liability in the
 * one table whose entire purpose is being trustworthy.
 */
#[Fillable([
    'physical_or_request_id', 'from_status', 'to_status', 'notes', 'changed_by', 'changed_at',
])]
class PhysicalOrRequestStatusLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_status' => PhysicalOrRequestStatus::class,
            'to_status' => PhysicalOrRequestStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    public function physicalOrRequest(): BelongsTo
    {
        return $this->belongsTo(PhysicalOrRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
