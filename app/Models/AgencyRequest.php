<?php

namespace App\Models;

use App\Enums\AgencyDocumentKind;
use App\Enums\AgencyRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'request_code', 'requested_by', 'agency_name', 'training_title',
    'proposed_start', 'proposed_end', 'proposed_venue', 'expected_participants',
    'status', 'ord_notified_at', 'assigned_to', 'assigned_at', 'review_notes',
    'requirements_text', 'requirements_sent_at', 'confirmed_start', 'confirmed_end',
    'confirmed_venue', 'confirmed_at', 'completion_submitted_at', 'payment_amount',
    'payment_verified_by', 'payment_verified_at', 'rejection_reason',
    'cancellation_reason', 'closed_at',
])]
class AgencyRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AgencyRequestStatus::class,
            'proposed_start' => 'date',
            'proposed_end' => 'date',
            'confirmed_start' => 'date',
            'confirmed_end' => 'date',
            'payment_amount' => 'decimal:2',
            'ord_notified_at' => 'datetime',
            'assigned_at' => 'datetime',
            'requirements_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completion_submitted_at' => 'datetime',
            'payment_verified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function paymentVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_verified_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AgencyRequestDocument::class)->orderBy('created_at');
    }

    /**
     * The most recent document of a kind.
     *
     * Re-uploading supersedes rather than replaces — the earlier version stays
     * on record, because "they sent us the wrong form first" is a thing that
     * gets disputed.
     */
    public function latestDocument(AgencyDocumentKind $kind): ?AgencyRequestDocument
    {
        return $this->documents
            ->where('kind', $kind)
            ->sortByDesc('created_at')
            ->first();
    }

    public function hasDocument(AgencyDocumentKind $kind): bool
    {
        return $this->latestDocument($kind) !== null;
    }

    /** Which of the required completion documents are still missing. */
    public function missingCompletionDocuments(): array
    {
        return array_values(array_filter(
            AgencyDocumentKind::requiredForCompletion(),
            fn (AgencyDocumentKind $kind) => ! $this->hasDocument($kind),
        ));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            AgencyRequestStatus::Completed,
            AgencyRequestStatus::Rejected,
            AgencyRequestStatus::Cancelled,
        ]);
    }

    /** Requests where the next move is HRD's. */
    public function scopeAwaitingStaff(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (AgencyRequestStatus $status) => $status->value,
            array_filter(
                AgencyRequestStatus::cases(),
                fn (AgencyRequestStatus $status) => $status->awaitsStaff(),
            ),
        ));
    }

    /**
     * Next code in the year's sequence.
     *
     * Called inside the transaction that inserts the row; the lockForUpdate in
     * AgencyRequestService is what keeps two simultaneous submissions from
     * claiming the same number.
     */
    public static function nextRequestCode(): string
    {
        $year = now()->format('Y');

        $latest = static::where('request_code', 'like', "AGR-{$year}-%")
            ->orderByDesc('request_code')
            ->value('request_code');

        return sprintf('AGR-%s-%03d', $year, $latest ? ((int) substr($latest, -3)) + 1 : 1);
    }
}
