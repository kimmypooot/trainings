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
                'We have confirmed your payment of PHP %s. Nothing further is needed.',
                number_format((float) $this->payment->amount, 2)
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
}
