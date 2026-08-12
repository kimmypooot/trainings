<?php

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\CancellationRequest;

class CancellationReviewed extends ParticipantNotification
{
    public function __construct(private readonly CancellationRequest $request) {}

    public function title(object $notifiable): string
    {
        $training = $this->request->registration->training->title;

        return $this->request->status === RequestStatus::Approved
            ? "Your withdrawal from “{$training}” is confirmed"
            : "Your withdrawal request for “{$training}” was declined";
    }

    public function body(object $notifiable): string
    {
        $body = $this->request->status === RequestStatus::Approved
            ? 'Your slot has been released. You may register again while the training is still open.'
            : 'Your slot remains reserved, so you are still expected to attend.';

        return $this->request->review_remarks
            ? "{$body} Remarks: {$this->request->review_remarks}"
            : $body;
    }

    public function url(object $notifiable): string
    {
        return route('registrations.index');
    }
}
