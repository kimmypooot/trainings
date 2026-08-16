<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only. Nothing in the app updates or deletes a row here — a trail that
 * can be edited answers no question worth asking.
 */
#[Fillable([
    'subject_type', 'subject_id', 'causer_id', 'causer_name',
    'action', 'description', 'properties', 'ip_address', 'user_agent', 'created_at',
])]
class ActivityLog extends Model
{
    /** `created_at` is written explicitly; there is no `updated_at` to keep. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /** The trail for one record. */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /**
     * Match a family of actions by prefix — `payment` catches
     * `payment.verified` and `payment.rejected` alike.
     */
    public function scopeInModule(Builder $query, string $module): Builder
    {
        return $query->where('action', 'like', $module.'.%');
    }

    /**
     * The actor as it should read on screen.
     *
     * Falls back to the name captured at write time, so a deleted account still
     * shows who acted rather than a blank.
     */
    public function actorName(): string
    {
        return $this->causer?->name ?? $this->causer_name ?? 'System';
    }
}
