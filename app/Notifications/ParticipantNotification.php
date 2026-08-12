<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The shape every CSC notification takes.
 *
 * NotificationController@index reads `title`, `body` and `url` out of the
 * stored payload, so subclasses describe themselves once and both the in-app
 * list and the email are built from that — the two can never drift apart.
 */
abstract class ParticipantNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function title(object $notifiable): string;

    abstract public function body(object $notifiable): string;

    /** Where the notification takes the participant when clicked. */
    abstract public function url(object $notifiable): string;

    /**
     * Text for the button in the email, when one makes sense.
     */
    public function action(object $notifiable): ?string
    {
        return 'View in CSC TIMS';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->url($notifiable),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title($notifiable))
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line($this->body($notifiable));

        if ($action = $this->action($notifiable)) {
            $mail->action($action, $this->url($notifiable));
        }

        return $mail->salutation('— Civil Service Commission Regional Office VIII');
    }
}
