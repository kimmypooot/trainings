<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per public lookup, ported from v1.
 *
 * Kept as an audit trail rather than a bare counter: when an employer queries
 * whether a certificate is genuine, CSC can say who asked and when.
 */
#[Fillable(['certificate_id', 'verified_at', 'ip_address', 'user_agent'])]
class CertificateVerification extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }
}
