<?php

namespace App\Enums;

/**
 * The agency-request document exchange, ported from v1's
 * `training_requests.status`.
 *
 * An agency writes to CSC asking for a training to be run for its own staff.
 * What follows is a correspondence, not an approval: HRD replies with the
 * requirements, the agency returns a signed confirmation, runs the training,
 * and finally submits the post-training documents and proof of payment.
 *
 * Deliberately not RequestStatus, and deliberately not the same table as v2's
 * `training_requests`. That one is a suggestion box — a participant proposing a
 * topic CSC might run for the region, which ends with a Training being created.
 * This one ends with an agency having run its own training and CSC holding the
 * paperwork. Same opening sentence, different process.
 */
enum AgencyRequestStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case RequirementsSent = 'requirements_sent';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under HRD Review',
            self::RequirementsSent => 'Requirements Sent',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /** What the requesting agency is told at this point. */
    public function requesterMessage(): string
    {
        return match ($this) {
            self::Pending => 'Your request has been received and is awaiting HRD.',
            self::UnderReview => 'HRD is reviewing your request.',
            self::RequirementsSent => 'HRD has sent the requirements. Return the signed confirmation form to proceed.',
            self::Confirmed => 'Your training is confirmed. Submit the post-training documents once it has run.',
            self::Completed => 'Your request is complete and the payment has been verified.',
            self::Rejected => 'Your request was not approved.',
            self::Cancelled => 'Your request was cancelled.',
        };
    }

    /**
     * The ordered correspondence. Rejection and cancellation are reachable
     * from anywhere open, so neither is part of the sequence.
     *
     * @return array<int, self>
     */
    public static function pipeline(): array
    {
        return [
            self::Pending,
            self::UnderReview,
            self::RequirementsSent,
            self::Confirmed,
            self::Completed,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /**
     * Whether the correspondence has got at least as far as $stage.
     *
     * A rejected or cancelled request has reached nothing further: it left the
     * pipeline, and showing it as part-way along would suggest it is still
     * moving.
     */
    public function hasReached(self $stage): bool
    {
        $pipeline = self::pipeline();
        $here = array_search($this, $pipeline, true);
        $there = array_search($stage, $pipeline, true);

        return $here !== false && $there !== false && $there <= $here;
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Which side acts next. The queue screens use this to separate "waiting on
     * us" from "waiting on them" — the single most useful split for HRD, who
     * are otherwise chasing requests that were never theirs to move.
     */
    public function awaitsStaff(): bool
    {
        return match ($this) {
            self::Pending, self::UnderReview, self::Confirmed => true,
            default => false,
        };
    }

    public function awaitsRequester(): bool
    {
        return $this === self::RequirementsSent;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases()
        );
    }
}
