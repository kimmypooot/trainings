<?php

namespace App\Enums;

/**
 * The refund pipeline, ported from v1's `refund_requests.refund_status`.
 *
 * Deliberately *not* the shared RequestStatus. A refund is not a yes/no
 * decision — it is money leaving the agency, and it crosses two units on the
 * way out: HRD reviews the claim, then the Management Services Division
 * actually disburses. Collapsing that to approved/rejected loses the only
 * thing a participant ever asks about, which is "where is it right now".
 *
 * The stages are strictly ordered and a request only ever moves forward, so
 * the pipeline can be read straight off the enum instead of being spelled out
 * in a `match` at every call site.
 */
enum RefundStatus: string
{
    case ForReview = 'for_review';
    case Processing = 'processing';
    case ForwardedToMsd = 'forwarded_to_msd';
    case ForRelease = 'for_release';
    case Refunded = 'refunded';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::ForReview => 'For Review',
            self::Processing => 'Processing',
            self::ForwardedToMsd => 'Forwarded to MSD',
            self::ForRelease => 'For Release',
            self::Refunded => 'Refunded',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * What the participant is told. The internal stage names describe CSC's
     * routing, which means nothing to someone waiting on their money.
     */
    public function participantMessage(): string
    {
        return match ($this) {
            self::ForReview => 'Your refund request has been received and is awaiting review.',
            self::Processing => 'Your refund request is now being processed.',
            self::ForwardedToMsd => 'Your refund request has been forwarded to MSD for disbursement.',
            self::ForRelease => 'Your refund is ready for release.',
            self::Refunded => 'Your refund has been completed.',
            self::Rejected => 'Your refund request was declined.',
        };
    }

    /**
     * The ordered pipeline. Rejection is reachable from anywhere and so is not
     * part of the sequence.
     *
     * @return array<int, self>
     */
    public static function pipeline(): array
    {
        return [self::ForReview, self::Processing, self::ForwardedToMsd, self::ForRelease, self::Refunded];
    }

    /** Nothing moves once the money is out or the claim is declined. */
    public function isTerminal(): bool
    {
        return $this === self::Refunded || $this === self::Rejected;
    }

    /** Still somewhere in the pipeline — the participant's "open" set. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * The single stage this one may advance to, or null at the end of the
     * pipeline. Rejection is handled separately because it needs a reason.
     */
    public function next(): ?self
    {
        $pipeline = self::pipeline();
        $position = array_search($this, $pipeline, true);

        if ($position === false) {
            return null;
        }

        return $pipeline[$position + 1] ?? null;
    }

    /**
     * Forward-only, one step at a time. A skipped stage is almost always a
     * misclick rather than an intent, and the audit trail is worthless if the
     * pipeline can be jumped.
     */
    public function canAdvanceTo(self $target): bool
    {
        return $this->next() === $target;
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
