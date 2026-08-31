<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandsMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Someone asked to move your account" — sent to the address being left.
 *
 * The counterpart to ConfirmEmailChange, and the more important of the two.
 * A hijacked session controls the app but not the inbox; changing the address
 * on the account is precisely how that session would turn itself into
 * permanent ownership, locking the real participant out of a system holding
 * their training records. This is the message that reaches the one person who
 * did not perform the action, while the old address still works.
 *
 * The new address is named in full rather than masked. The recipient is the
 * account's owner and is being asked to judge whether this was them, which they
 * cannot do against "j•••@•••.com".
 *
 * Like PasswordChanged: not a ParticipantNotification — it must not appear in
 * the in-app list, which a hijacked session could simply read and dismiss — and
 * not queued, because a security notice that fails silently is worse than none.
 */
class EmailChangeRequested extends Notification
{
    use BrandsMail;
    use Queueable;

    public function __construct(private string $newEmail) {}

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
                ->subject('A change of email address was requested on your CSC TIMS account')
                ->greeting($this->greetingFor($notifiable))
                ->line('A request was made to move your CSC TIMS account to **'.$this->newEmail.'**.')
                ->line('Nothing has changed yet. The move only takes effect if the confirmation link sent to that address is opened.')
                ->line('If this was you, no action is needed here — just confirm from the new address.')
                ->line('**If this was not you, someone else may have access to your account.** Sign in and cancel the request from your profile, change your password, and contact the CSC Regional Office VIII.')
                ->action('Go to my profile', route('profile.edit'))
                ->salutation($this->signature()),
            'A move to '.$this->newEmail.' was requested. If this was not you, act now.'
        );
    }
}
