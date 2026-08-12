<?php

namespace App\Notifications;

use App\Models\Training;

/**
 * Sent ahead of a training the participant is confirmed for.
 */
class TrainingReminder extends ParticipantNotification
{
    public function __construct(private readonly Training $training) {}

    public function title(object $notifiable): string
    {
        return "Reminder: “{$this->training->title}” starts {$this->training->starts_at->diffForHumans()}";
    }

    public function body(object $notifiable): string
    {
        return sprintf(
            '%s begins on %s at %s and runs for %d day(s). Have your QR code ready for check-in.',
            $this->training->title,
            $this->training->starts_at->format('d M Y, g:i A'),
            $this->training->venue,
            $this->training->duration_days
        );
    }

    public function url(object $notifiable): string
    {
        return route('qr.show');
    }

    public function action(object $notifiable): ?string
    {
        return 'Open my QR code';
    }
}
