<?php

namespace App\Notifications;

use App\Enums\RefundStatus;
use App\Models\RefundRequest;

/**
 * Sent on every stage change, not only at the end.
 *
 * v1 notified at each step and that is the behaviour worth keeping: a refund
 * can sit with MSD for weeks, and silence during that stretch is what makes
 * participants ring HRD. The stage names themselves are internal, so the
 * wording comes from RefundStatus::participantMessage().
 */
class RefundReviewed extends ParticipantNotification
{
    public function __construct(private readonly RefundRequest $request) {}

    public function title(object $notifiable): string
    {
        return match ($this->request->status) {
            RefundStatus::Refunded => 'Your refund has been released',
            RefundStatus::Rejected => 'Your refund request was declined',
            default => "Refund {$this->request->request_code} update",
        };
    }

    public function body(object $notifiable): string
    {
        $amount = number_format((float) $this->request->amount, 2);
        $body = "{$this->request->status->participantMessage()} (PHP {$amount}, ref. {$this->request->request_code})";

        // A decline is the one stage where the reason is the whole message, so
        // it is always appended; mid-pipeline notes are internal.
        $remarks = $this->request->status === RefundStatus::Rejected
            ? $this->request->rejection_reason
            : null;

        return $remarks ? "{$body} Reason: {$remarks}" : $body;
    }

    public function url(object $notifiable): string
    {
        return route('payments.index');
    }
}
