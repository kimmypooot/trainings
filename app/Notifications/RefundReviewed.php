<?php

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\RefundRequest;

class RefundReviewed extends ParticipantNotification
{
    public function __construct(private readonly RefundRequest $request) {}

    public function title(object $notifiable): string
    {
        return $this->request->status === RequestStatus::Approved
            ? 'Your refund has been approved'
            : 'Your refund request was declined';
    }

    public function body(object $notifiable): string
    {
        $amount = number_format((float) $this->request->amount, 2);

        $body = $this->request->status === RequestStatus::Approved
            ? "A refund of PHP {$amount} has been approved and processed."
            : "Your request for a refund of PHP {$amount} was not approved.";

        return $this->request->review_remarks
            ? "{$body} Remarks: {$this->request->review_remarks}"
            : $body;
    }

    public function url(object $notifiable): string
    {
        return route('payments.index');
    }
}
