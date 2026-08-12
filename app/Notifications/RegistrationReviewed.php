<?php

namespace App\Notifications;

use App\Enums\RegistrationStatus;
use App\Models\Registration;

/**
 * HRD has decided on a registration.
 *
 * One class rather than three: approval, waitlisting and rejection differ only
 * in wording, and splitting them would mean three places to keep in step.
 */
class RegistrationReviewed extends ParticipantNotification
{
    public function __construct(private readonly Registration $registration) {}

    public function title(object $notifiable): string
    {
        $training = $this->registration->training->title;

        return match ($this->registration->status) {
            RegistrationStatus::Approved => "You're confirmed for “{$training}”",
            RegistrationStatus::Waitlisted => "You're on the waitlist for “{$training}”",
            RegistrationStatus::Rejected => "Your registration for “{$training}” was not approved",
            default => "Update on your registration for “{$training}”",
        };
    }

    public function body(object $notifiable): string
    {
        $training = $this->registration->training;
        $remarks = $this->registration->review_remarks;

        $body = match ($this->registration->status) {
            RegistrationStatus::Approved => sprintf(
                'Your slot is confirmed for %s at %s. Bring your QR code — it is how we take attendance at the door.',
                $training->starts_at->format('d M Y, g:i A'),
                $training->venue
            ),
            RegistrationStatus::Waitlisted => 'You will be moved up automatically if a slot frees up before the training starts.',
            RegistrationStatus::Rejected => 'Your registration was not approved on this occasion.',
            default => 'Your registration has been updated.',
        };

        // A rejection is required to carry a reason, so it always lands here.
        return $remarks ? "{$body} Remarks: {$remarks}" : $body;
    }

    public function url(object $notifiable): string
    {
        return route('registrations.index');
    }
}
