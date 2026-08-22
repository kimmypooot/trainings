<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * The three things every CSC email agrees on.
 *
 * ParticipantNotification already shaped its twelve subclasses this way, but
 * the password-reset, password-changed and email-verification notices build
 * their own MailMessage — they have no single body() to describe themselves
 * with — and so drifted the moment the shared shape gained anything. They ended
 * up the odd ones out on all three counts below, which is the worst possible
 * set to be inconsistent about: they are the security mail, the messages a
 * participant is most likely to scrutinise for signs of a forgery.
 *
 * A trait rather than a base class because those three legitimately differ in
 * structure, and inheritance would have forced them into a shape that does not
 * fit.
 */
trait BrandsMail
{
    /**
     * "Hello Juan," — not "Hello JUAN D. DELA CRUZ,".
     *
     * Names are stored upper-cased, so the full name in a greeting reads as
     * shouting. firstName() is the same helper the sign-in splash greets with.
     */
    protected function greetingFor(object $notifiable): string
    {
        $given = method_exists($notifiable, 'firstName') ? $notifiable->firstName() : null;

        return 'Hello '.($given ?: 'there').',';
    }

    /**
     * The sign-off, from config rather than a literal.
     *
     * Hard-coded in three places, this was the string that would still say
     * Regional Office VIII after a deployment corrected every other mention of
     * itself.
     */
    protected function signature(): string
    {
        return '— '.config('office.name');
    }

    /**
     * The inbox preview line; see resources/views/vendor/mail/html/layout.blade.php.
     *
     * Written straight onto viewData because MailMessage::with() appends a body
     * line rather than passing view data.
     */
    protected function withPreheader(MailMessage $mail, string $preheader): MailMessage
    {
        $mail->viewData['preheader'] = $preheader;

        return $mail;
    }
}
