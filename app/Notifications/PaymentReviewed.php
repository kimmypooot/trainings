<?php

namespace App\Notifications;

use App\Enums\PaymentStatus;
use App\Models\Payment;

class PaymentReviewed extends ParticipantNotification
{
    public function __construct(private readonly Payment $payment) {}

    public function title(object $notifiable): string
    {
        $training = $this->payment->training->title;

        return $this->payment->status === PaymentStatus::Verified
            ? "Payment received for “{$training}”"
            : "There is a problem with your payment for “{$training}”";
    }

    public function body(object $notifiable): string
    {
        if ($this->payment->status === PaymentStatus::Verified) {
            return sprintf(
                'We have confirmed your payment of PHP %s. Nothing further is needed.%s',
                number_format((float) $this->payment->amount, 2),
                $this->joinLine()
            );
        }

        return sprintf(
            'Your payment of PHP %s could not be verified. Reason: %s',
            number_format((float) $this->payment->amount, 2),
            $this->payment->rejection_reason ?: 'not stated'
        );
    }

    public function url(object $notifiable): string
    {
        return route('payments.index');
    }

    /**
     * The join link, when verifying this payment is what unlocked it.
     *
     * Verification is the moment a paid online run becomes reachable, so the
     * link rides along with the receipt rather than making the participant go
     * looking for it. The decision of whether they may have it is not retaken
     * here — `mayViewMeetingLink()` is the single rule, and it still refuses
     * while the registration itself is unapproved.
     *
     * Re-read from the database rather than trusted from the loaded relation:
     * this notification is queued, and the registration may have been withdrawn
     * between the officer clicking verify and the mail going out.
     */
    private function joinLine(): string
    {
        $registration = $this->payment->registration?->fresh(['training', 'payments']);

        if ($registration === null || ! $registration->mayViewMeetingLink()) {
            return '';
        }

        $link = $registration->training->meeting_link;

        return blank($link) ? '' : " Your join link for this training: {$link}";
    }
}
