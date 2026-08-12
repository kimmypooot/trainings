<?php

namespace App\Models;

use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'registration_id', 'user_id', 'training_id', 'certificate_number', 'verification_code',
    'file_path', 'generated_at', 'generated_by', 'email_sent_at',
])]
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(CertificateVerification::class);
    }

    /** The public verification URL, printed on the document as a QR code. */
    public function verificationUrl(): string
    {
        return route('certificates.verify', ['code' => $this->verification_code]);
    }

    public function isReleased(): bool
    {
        return $this->generated_at !== null && $this->file_path !== null;
    }
}
