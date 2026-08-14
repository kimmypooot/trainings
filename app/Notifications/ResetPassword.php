<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password-reset email.
 *
 * Deliberately separate from ParticipantNotification: it is fired by the
 * password broker rather than a domain workflow, so it must not land in the
 * in-app notification list, and the reset link is dead the moment it is used.
 *
 * Not queued on purpose. A reset link is the one email the recipient is
 * actively waiting for, so sending it synchronously is the safer failure mode
 * than hoping the queue worker is up.
 */
class ResetPassword extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $email,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your CSC TIMS password')
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset your password', $this->resetUrl($notifiable))
            ->line('This link will expire in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you did not request a reset, no further action is needed.')
            ->salutation('— Civil Service Commission Regional Office VIII');
    }

    private function resetUrl(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset() ?: $this->email,
        ]));
    }
}
