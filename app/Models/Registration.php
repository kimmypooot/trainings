<?php

namespace App\Models;

use App\Enums\ChargeTo;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\SupervisoryDocumentStatus;
use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id', 'training_id', 'status', 'charge_to', 'needs_certificate',
    'supporting_document_path', 'supervisory_document_status',
    'supervisory_document_reviewed_by', 'supervisory_document_reviewed_at',
    'supervisory_document_remarks', 'registered_at', 'cancelled_at', 'attended_at',
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
            'charge_to' => ChargeTo::class,
            'needs_certificate' => 'boolean',
            'registered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attended_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'supervisory_document_status' => SupervisoryDocumentStatus::class,
            'supervisory_document_reviewed_at' => 'datetime',
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

    /** The staff member who last verified or rejected the supervisory document. */
    public function supervisoryDocumentReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisory_document_reviewed_by');
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

    /**
     * Whether the office has accepted *something* against this registration.
     *
     * Either money or a promissory note — both are verified by the collecting
     * officer, and both mean the office has agreed to let this person in. This
     * is the gate on attendance: the join link, the venue, the room.
     *
     * Verification is what counts, not the upload. A pending proof is a claim,
     * not a receipt, and treating it as one would admit anyone willing to
     * attach a screenshot.
     */
    public function hasSettledFee(): bool
    {
        if (! $this->training->payment_required) {
            return true;
        }

        return $this->payments->contains(
            fn (Payment $payment) => $payment->status === PaymentStatus::Verified
        );
    }

    /**
     * Whether the money itself has actually arrived.
     *
     * Deliberately stricter than hasSettledFee(): a promissory note gets the
     * participant through the door but not onto a certificate. The certificate
     * is the office's last piece of leverage over an unpaid fee, and issuing
     * one against a promise spends it for nothing.
     */
    public function hasClearedFee(): bool
    {
        if (! $this->training->payment_required) {
            return true;
        }

        return $this->payments->contains(
            fn (Payment $payment) => $payment->status === PaymentStatus::Verified
                && $payment->payment_method->isSettlement()
        );
    }

    /**
     * The query-side twin of hasClearedFee().
     *
     * Kept beside it so the two cannot drift: a certificate run that used a
     * looser filter than the service's own guard would just generate a batch of
     * exceptions, and one that used a stricter filter would silently skip
     * people who had paid.
     */
    public function scopeFeeCleared(Builder $query): Builder
    {
        return $query->where(fn (Builder $registration) => $registration
            ->whereHas('training', fn (Builder $training) => $training->where('payment_required', false))
            ->orWhereHas('payments', fn (Builder $payment) => $payment
                ->where('status', PaymentStatus::Verified)
                ->whereNot('payment_method', PaymentMethod::Promissory)
            )
        );
    }

    /**
     * Whether the participant may see the training's join link.
     *
     * Two conditions, and both are the point. The fee must be settled — an
     * online run's link *is* the training, so handing it out before payment
     * gives the whole thing away for free. And the registration must have been
     * approved: a link that goes out with the registration itself would reach
     * anyone who can click Register, which is how open online sessions get
     * crashed. Completed counts too, since recordings and follow-ups often live
     * behind the same link.
     */
    public function mayViewMeetingLink(): bool
    {
        $reviewed = in_array(
            $this->status,
            [RegistrationStatus::Approved, RegistrationStatus::Completed],
            true
        );

        return $reviewed && $this->hasSettledFee();
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
