<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'request_code', 'payment_id', 'amount', 'reason', 'account_name', 'bank_name',
    'account_number', 'proof_path', 'status', 'reviewed_by', 'reviewed_at',
    'review_remarks', 'rejection_reason', 'refunded_at',
])]
class RefundRequest extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => RefundStatus::class,
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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
        // Tie-broken on id: `changed_at` is second-accurate, so two entries
        // written in one transaction would otherwise read back in whatever
        // order the database chose.
        return $this->hasMany(RefundStatusLog::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    /** Anything still moving through the pipeline. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [RefundStatus::Refunded, RefundStatus::Rejected]);
    }

    public function scopeAwaitingAction(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::ForReview);
    }

    /**
     * Masked for display. The full account number is needed to disburse, but
     * it has no business sitting in a roster on a shared screen — every list
     * view shows this instead.
     */
    public function maskedAccountNumber(): ?string
    {
        if (blank($this->account_number)) {
            return null;
        }

        $tail = substr($this->account_number, -4);

        return str_repeat('•', max(0, strlen($this->account_number) - 4)).$tail;
    }

    /**
     * Next code in the year's sequence, matching v1's RFD-YYYY-NNN.
     *
     * Called inside the same transaction that inserts the row, so the
     * lockForUpdate in RefundService is what actually keeps two simultaneous
     * requests from claiming the same number.
     */
    public static function nextRequestCode(): string
    {
        $year = now()->format('Y');

        $latest = static::where('request_code', 'like', "RFD-{$year}-%")
            ->orderByDesc('request_code')
            ->value('request_code');

        $sequence = $latest ? ((int) substr($latest, -3)) + 1 : 1;

        return sprintf('RFD-%s-%03d', $year, $sequence);
    }
}
