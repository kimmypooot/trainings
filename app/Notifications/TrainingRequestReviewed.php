<?php

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\TrainingRequest;

class TrainingRequestReviewed extends ParticipantNotification
{
    public function __construct(private readonly TrainingRequest $request) {}

    public function title(object $notifiable): string
    {
        return $this->request->status === RequestStatus::Approved
            ? "Your training request “{$this->request->title}” was approved"
            : "Your training request “{$this->request->title}” was declined";
    }

    public function body(object $notifiable): string
    {
        $body = $this->request->status === RequestStatus::Approved
            ? 'CSC will schedule this training and announce it once the details are set.'
            : 'CSC is unable to run this training at present.';

        return $this->request->review_remarks
            ? "{$body} Remarks: {$this->request->review_remarks}"
            : $body;
    }

    public function url(object $notifiable): string
    {
        return route('training-requests.index');
    }
}
