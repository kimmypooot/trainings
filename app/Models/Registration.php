<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id', 'training_id', 'status', 'registered_at', 'cancelled_at', 'attended_at',
    'reviewed_by', 'reviewed_at', 'review_remarks',
])]
class Registration extends Model
{
    /** @use HasFactory<RegistrationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'registered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attended_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(RegistrationOutput::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** A withdrawal already awaiting review — the slot is still held. */
    public function hasPendingCancellation(): bool
    {
        return $this->cancellationRequests
            ->contains(fn (CancellationRequest $request) => $request->status->isPending());
    }

    public function isActive(): bool
    {
        return $this->status->occupiesSlot();
    }

    /**
     * Days the participant was accounted for, out of the training's total.
     *
     * Reads from the loaded relation when it is there, so a roster listing does
     * not fire a query per row.
     */
    public function creditedDays(): int
    {
        return $this->attendances
            ->filter(fn (Attendance $attendance) => $attendance->credits())
            ->count();
    }

    /**
     * Whether attendance is sufficient to mark the registration complete.
     *
     * CSC requires the majority of days rather than a perfect record — a single
     * missed afternoon should not cost someone their certificate.
     */
    public function hasSufficientAttendance(): bool
    {
        $required = (int) ceil(max(1, $this->training->duration_days ?? 1) / 2);

        return $this->creditedDays() >= $required;
    }
}
