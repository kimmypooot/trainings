<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\BrandsMail;
use App\Support\EmailChangeService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * "Confirm your new email address" — sent to the address being moved *to*.
 *
 * Addressed on demand rather than through the account, because the whole
 * purpose is to reach an inbox the account does not have yet: sent the ordinary
 * way it would land in the old one and prove nothing. The user is therefore
 * carried explicitly, which is also what lets it greet someone by name.
 *
 * Not queued, for the reason PasswordChanged gives: the participant is sitting
 * on the confirmation screen waiting for it, and a security-adjacent mail that
 * silently fails because no worker is running is the wrong failure mode.
 */
class ConfirmEmailChange extends Notification
{
    use BrandsMail;
    use Queueable;

    public function __construct(
        private User $user,
        private string $newEmail,
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
        return $this->withPreheader(
            (new MailMessage)
                ->subject('Confirm your new CSC TIMS email address')
                // The account's owner, not the anonymous recipient this is
                // routed to — see the note on the constructor.
                ->greeting($this->greetingFor($this->user))
                ->line('A request was made to move your CSC TIMS account to this email address.')
                ->line('Click below to confirm you can read this inbox. Until you do, your account keeps its current address and nothing changes.')
                ->action('Confirm this address', $this->confirmationUrl())
                ->line('This link expires in '.EmailChangeService::LINK_TTL_MINUTES.' minutes.')
                ->line('If you were not expecting this, you can ignore this email — no change will be made, and the account will stay where it is.')
                ->salutation($this->signature()),
            'Confirm your new address to finish moving your CSC TIMS account.'
        );
    }

    /**
     * The signed link.
     *
     * The hash covers the *pending* address, so a request that is cancelled or
     * replaced takes its old links down with it — a second request cannot be
     * completed with the first one's email.
     */
    private function confirmationUrl(): string
    {
        return URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(EmailChangeService::LINK_TTL_MINUTES),
            [
                'id' => $this->user->getKey(),
                'hash' => sha1($this->newEmail),
            ]
        );
    }
}
