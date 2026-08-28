<?php

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\RegistrationOutput;

class OutputReviewed extends ParticipantNotification
{
    public function __construct(private readonly RegistrationOutput $output) {}

    public function title(object $notifiable): string
    {
        $training = $this->output->registration->training->title;

        return $this->output->status === RequestStatus::Approved
            ? "Your output for “{$training}” was approved"
            : "Your output for “{$training}” needs another look";
    }

    public function body(object $notifiable): string
    {
        $body = $this->output->status === RequestStatus::Approved
            ? "\"{$this->output->title}\" has been accepted."
            : "\"{$this->output->title}\" was not accepted and needs to be resubmitted.";

        return $this->output->review_remarks
            ? "{$body} Remarks: {$this->output->review_remarks}"
            : $body;
    }

    public function url(object $notifiable): string
    {
        return route('registrations.index');
    }
}
