<?php

namespace App\Models;

use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What `casts()` cannot tell Larastan about this model's relations — see
 * CLAUDE.md's note on `casts()` vs the `$casts` property. Without these,
 * `->user` and `->training` resolve to the base `Model`, and every
 * `->name`/`->title`/`->signatory_name` read off them looks undefined.
 *
 * @property-read User $user
 * @property-read Training $training
 * @property-read Registration $registration
 */
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
