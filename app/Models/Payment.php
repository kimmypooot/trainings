<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RequestStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'registration_id', 'user_id', 'training_id', 'amount', 'payment_method',
    'reference_number', 'payment_date', 'proof_path', 'status', 'verified_by',
    'verified_at', 'rejection_reason', 'remarks',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'verified_at' => 'datetime',
            'status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
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

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Pending);
    }

    /** A refund already awaiting review blocks a second one. */
    public function hasPendingRefund(): bool
    {
        return $this->refundRequests
            ->contains(fn (RefundRequest $request) => $request->status->isPending());
    }

    /** Already refunded in full, so nothing further can be claimed. */
    public function hasBeenRefunded(): bool
    {
        return $this->refundRequests
            ->contains(fn (RefundRequest $request) => $request->status === RequestStatus::Approved);
    }
}
