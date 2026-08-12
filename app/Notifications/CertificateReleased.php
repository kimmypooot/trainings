<?php

namespace App\Notifications;

use App\Models\Certificate;

class CertificateReleased extends ParticipantNotification
{
    public function __construct(private readonly Certificate $certificate) {}

    public function title(object $notifiable): string
    {
        return "Your certificate for “{$this->certificate->training->title}” is ready";
    }

    public function body(object $notifiable): string
    {
        return sprintf(
            'Certificate %s has been issued. Anyone can confirm it is genuine by scanning the QR code on the document.',
            $this->certificate->certificate_number
        );
    }

    public function url(object $notifiable): string
    {
        return route('certificates.index');
    }

    public function action(object $notifiable): ?string
    {
        return 'Download my certificate';
    }
}
