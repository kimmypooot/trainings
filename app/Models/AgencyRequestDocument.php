<?php

namespace App\Models;

use App\Enums\AgencyDocumentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agency_request_id', 'kind', 'file_path', 'original_filename',
    'file_size', 'mime_type', 'uploaded_by', 'created_at',
])]
class AgencyRequestDocument extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'kind' => AgencyDocumentKind::class,
            'created_at' => 'datetime',
        ];
    }

    public function agencyRequest(): BelongsTo
    {
        return $this->belongsTo(AgencyRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeOfKind(Builder $query, AgencyDocumentKind $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /** Rounded for display; the exact byte count is nobody's question. */
    public function readableSize(): string
    {
        $kb = $this->file_size / 1024;

        return $kb < 1024
            ? round($kb).' KB'
            : round($kb / 1024, 1).' MB';
    }
}
