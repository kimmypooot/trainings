<?php

namespace App\Notifications;

use App\Models\Registration;
use App\Models\Training;

/**
 * Tells a participant their slot has been moved to another run.
 *
 * Not delayed by the undo window like RegistrationReviewed, because a transfer
 * is not reversible through UndoService — and the dates have changed, which is
 * something a participant needs to know before they turn up at the old venue.
 */
class RegistrationTransferred extends ParticipantNotification
{
    public function __construct(
        private readonly Registration $registration,
        private readonly Training $from,
        private readonly string $reason,
    ) {}

    public function title(object $notifiable): string
    {
        return 'Your training has been rescheduled';
    }

    public function body(object $notifiable): string
    {
        $to = $this->registration->training;

        return sprintf(
            'Your registration for “%s” has been moved to “%s”, running %s at %s. Reason: %s',
            $this->from->title,
            $to->title,
            $to->starts_at->format('d M Y'),
            $to->venue,
            $this->reason,
        );
    }

    public function url(object $notifiable): string
    {
        return route('trainings.show', $this->registration->training->slug);
    }

    public function action(object $notifiable): ?string
    {
        return 'View the new schedule';
    }
}
