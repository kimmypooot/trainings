<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PhysicalOrRequestStatus;
use App\Enums\RefundStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'registration_id', 'user_id', 'training_id', 'amount', 'payment_method',
    'reference_number', 'or_number', 'or_date', 'collecting_officer_id',
    'payment_date', 'proof_path', 'status', 'verified_by',
    'verified_at', 'rejection_reason', 'remarks',
    'prime_hrm_discount', 'discount_amount',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'prime_hrm_discount' => 'boolean',
            'payment_date' => 'date',
            'or_date' => 'date',
            'verified_at' => 'datetime',
            'status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
        ];
    }

    /**
     * What the fee would have been without the discount.
     *
     * Derived from the two stored figures rather than read back off the
     * training, so it is the gross this payment was actually assessed against
     * — a later change to the course fee cannot move it.
     */
    public function grossAmount(): float
    {
        return (float) $this->amount + (float) $this->discount_amount;
    }

    /**
     * The discount as a percentage, for display.
     *
     * Derived rather than stored: the pair of amounts already determines it, and
     * a stored rate could disagree with them.
     */
    public function discountRate(): ?float
    {
        $gross = $this->grossAmount();

        if (! $this->prime_hrm_discount || $gross <= 0.0) {
            return null;
        }

        return round((float) $this->discount_amount / $gross * 100, 2);
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

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Who issued the official receipt. */
    public function collectingOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collecting_officer_id');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function physicalOrRequests(): HasMany
    {
        return $this->hasMany(PhysicalOrRequest::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Pending);
    }

    /**
     * A refund anywhere in the pipeline blocks a second one — not just one
     * awaiting review. A claim sitting with MSD is still a claim.
     */
    public function hasPendingRefund(): bool
    {
        return $this->refundRequests
            ->contains(fn (RefundRequest $request) => $request->status->isOpen());
    }

    /** Already refunded in full, so nothing further can be claimed. */
    public function hasBeenRefunded(): bool
    {
        return $this->refundRequests
            ->contains(fn (RefundRequest $request) => $request->status === RefundStatus::Refunded);
    }

    /**
     * A physical-OR request anywhere in the pipeline blocks a second one — a
     * receipt already being prepared or shipped is still one in flight.
     */
    public function hasPendingPhysicalOrRequest(): bool
    {
        return $this->physicalOrRequests
            ->contains(fn (PhysicalOrRequest $request) => $request->status->isOpen());
    }

    /** A physical copy was already delivered for this payment. */
    public function hasDeliveredPhysicalOrRequest(): bool
    {
        return $this->physicalOrRequests
            ->contains(fn (PhysicalOrRequest $request) => $request->status === PhysicalOrRequestStatus::Delivered);
    }
}
