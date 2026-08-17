<?php

namespace App\Models;

use App\Enums\PhysicalOrRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'request_code', 'user_id', 'payment_id', 'courier_fee', 'status', 'proof_path',
    'notes', 'verified_by', 'verified_at', 'courier_name', 'tracking_number',
    'shipped_at', 'delivered_at', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    'review_remarks',
])]
class PhysicalOrRequest extends Model
{
    protected function casts(): array
    {
        return [
            'courier_fee' => 'decimal:2',
            'status' => PhysicalOrRequestStatus::class,
            'verified_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The audit trail, oldest first — the order it reads in on screen.
     */
    public function statusLogs(): HasMany
    {
        // Tie-broken on id, because `changed_at` is only accurate to the
        // second and a single transaction writes two entries — filing a
        // request with its proof attached logs both "filed" and "proof
        // uploaded" at the same instant. Without the tie-break the order
        // between them is whatever the database feels like, and the trail is
        // rendered to an officer as the history of the request.
        return $this->hasMany(PhysicalOrRequestStatusLog::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    /** Anything still moving through the pipeline. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [PhysicalOrRequestStatus::Delivered, PhysicalOrRequestStatus::Rejected]);
    }

    /** The queue an officer actually works first: fee paid, waiting on them. */
    public function scopeAwaitingAction(Builder $query): Builder
    {
        return $query->where('status', PhysicalOrRequestStatus::PaymentVerificationPending);
    }

    /**
     * Next code in the year's sequence, OR-YYYY-NNN.
     *
     * Called inside the same transaction that inserts the row, so the
     * lockForUpdate in PhysicalOrRequestService is what actually keeps two
     * simultaneous requests from claiming the same number.
     */
    public static function nextRequestCode(): string
    {
        $year = now()->format('Y');

        $latest = static::where('request_code', 'like', "OR-{$year}-%")
            ->orderByDesc('request_code')
            ->value('request_code');

        $sequence = $latest ? ((int) substr($latest, -3)) + 1 : 1;

        return sprintf('OR-%s-%03d', $year, $sequence);
    }
}
