<?php

namespace App\Notifications;

/**
 * A free-text message composed by HRD and sent to a training's participants.
 *
 * Ported from v1's `admin/hrd/send-emails.php`.
 */
class StaffAnnouncement extends ParticipantNotification
{
    public function __construct(
        private readonly string $subject,
        private readonly string $message,
        private readonly ?string $link = null,
    ) {}

    public function title(object $notifiable): string
    {
        return $this->subject;
    }

    public function body(object $notifiable): string
    {
        return $this->message;
    }

    public function url(object $notifiable): string
    {
        return $this->link ?? route('dashboard');
    }

    /**
     * A plain announcement rarely has anywhere useful to send someone, so the
     * button only appears when HRD supplied a link.
     */
    public function action(object $notifiable): ?string
    {
        return $this->link ? 'View details' : null;
    }
}
