<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandsMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The email-verification link.
 *
 * Like ResetPassword, this is deliberately separate from ParticipantNotification:
 * it is fired by the auth flow rather than a domain workflow, so it must not land
 * in the in-app notification list. It is also sent synchronously — verification
 * is the one email a fresh account is actively waiting for, so relying on the
 * queue worker is the wrong failure mode.
 */
class VerifyEmail extends Notification
{
    use BrandsMail;
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withPreheader(
            (new MailMessage)
                ->subject('Verify your CSC TIMS email')
                ->greeting($this->greetingFor($notifiable))
                ->line('Thanks for registering with CSC TIMS. Please confirm that this is your email address by clicking the button below.')
                ->action('Verify my email', $this->verificationUrl($notifiable))
                ->line('This link will expire in 60 minutes. If you did not create this account, you can ignore this email.')
                ->salutation($this->signature()),
            'Confirm your email address to activate your CSC TIMS account.'
        );
    }

    /**
     * The one-time signed URL for this user, mirroring the framework's own
     * format so the routes and guards behave exactly like Laravel's.
     */
    private function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }
}
