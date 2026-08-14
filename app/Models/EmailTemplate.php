<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'code', 'subject', 'body', 'category', 'is_system', 'created_by',
])]
class EmailTemplate extends Model
{
    /** The categories HRD files templates under. */
    public const CATEGORIES = ['general', 'training', 'payment', 'certificate', 'reminder'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Templates HRD may delete — the system ones are load-bearing. */
    public function scopeDeletable(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
