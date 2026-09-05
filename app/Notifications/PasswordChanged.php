<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandsMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your sign-in details changed" — sent after a password is created or rotated.
 *
 * This is a security notice, not a receipt. Its job is to reach the one person
 * who did *not* perform the action: if a session is hijacked, the attacker
 * controls the app but not the inbox, so this is the message that tells the
 * real participant something happened and gives them somewhere to go.
 *
 * That matters most for the *created* case. A password on a Google-only
 * account is a second, durable way in — one that keeps working after the
 * participant revokes the app's access to their Google account, so it cannot
 * be undone from Google's side. Announcing it is what keeps that from being
 * silent.
 *
 * Like ResetPassword and VerifyEmail, it is deliberately not a
 * ParticipantNotification: it belongs to the auth flow rather than a training
 * workflow, so it must not appear in the in-app notification list — which a
 * hijacked session could simply read and dismiss.
 *
 * Not queued, for the same reason those two are not: a security notice that
 * silently fails to send because no worker is running is worse than useless,
 * and unlike them nobody is sitting waiting for this one, so its absence would
 * never be noticed or reported. Changing a password is rare enough that the
 * SMTP round trip inside the request is a fair price.
 */
class PasswordChanged extends Notification
{
    use BrandsMail;
    use Queueable;

    /**
     * @param  bool  $created  True when this was the account's first password,
     *                         false when an existing one was rotated.
     */
    public function __construct(private bool $created) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->created
                ? 'A password was added to your CSC TIMS account'
                : 'Your CSC TIMS password was changed')
            ->greeting($this->greetingFor($notifiable));

        if ($this->created) {
            $message
                ->line('A password has been created for your CSC TIMS account, which was originally set up with Google.')
                ->line('You can now sign in either with your email address and this password, or with Google as before.');
        } else {
            $message->line('The password for your CSC TIMS account was changed just now.');
        }

        $this->withPreheader($message, $this->created
            ? 'A password was added to your account. If this was not you, act now.'
            : 'Your password was changed. If this was not you, act now.');

        return $message
            ->line('If this was you, nothing further is needed — this message is only to let you know.')
            // The office from config, like the sign-off directly below. These
            // two disagreed: signature() has read config since it was written,
            // while this line still named Regional Office VIII, so a security
            // email could credit two different offices in one message.
            ->line('**If this was not you, someone else may have access to your account.** Reset your password immediately and contact '.config('office.name').'.')
            ->action('Reset my password', route('password.request'))
            ->salutation($this->signature());
    }
}
