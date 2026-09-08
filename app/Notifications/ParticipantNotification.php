<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandsMail;
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
    use BrandsMail;
    use Queueable;

    abstract public function title(object $notifiable): string;

    abstract public function body(object $notifiable): string;

    /** Where the notification takes the participant when clicked. */
    abstract public function url(object $notifiable): string;

    /**
     * Which family of event this is.
     *
     * Names a key in resources/js/activityTone.ts, which is what decides the
     * glyph and the tone the in-app list draws the row with. The notifications
     * page had been rendering all fifty of a participant's notifications as
     * identical bordered boxes — the one screen somebody opens *because*
     * something happened was the screen that would not say what.
     *
     * Declared here rather than mapped from the class name in the controller,
     * for the reason the docblock above gives about title/body/url: a
     * notification describes itself once, in one file, and the list and the
     * email are both built from that. A map elsewhere is a second place to
     * remember.
     *
     * The default is deliberately the neutral one. A new notification that
     * forgets to override this is legible, just undifferentiated — and
     * NotificationKindTest names every subclass, so it does not stay forgotten.
     */
    public function kind(): string
    {
        return 'announcement';
    }

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
            'kind' => $this->kind(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title($notifiable))
            ->greeting($this->greetingFor($notifiable))
            ->line($this->body($notifiable));

        if ($action = $this->action($notifiable)) {
            $mail->action($action, $this->url($notifiable));
        }

        // The body doubles as the inbox preview: clients take their snippet
        // from the first text they find, which would otherwise be the greeting —
        // the one line identical on every message we send.
        $this->withPreheader($mail, $this->body($notifiable));

        return $mail->salutation($this->signature());
    }
}
