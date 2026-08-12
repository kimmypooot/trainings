<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post-training deliverable uploaded by a participant, ported from v1's
 * `submit-output.php`.
 */
#[Fillable([
    'registration_id', 'title', 'description', 'file_path', 'original_filename',
    'file_size', 'mime_type', 'status', 'reviewed_by', 'reviewed_at', 'review_remarks',
])]
class RegistrationOutput extends Model
{
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'reviewed_at' => 'datetime',
            'file_size' => 'integer',
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

    /** Human-readable size for the UI. */
    public function readableSize(): string
    {
        $units = ['B', 'KB', 'MB'];
        $size = $this->file_size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 1).' '.$units[$unit];
    }
}
