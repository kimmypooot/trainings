<?php

namespace App\Listeners;

use App\Support\MailBranding;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Attaches the CSC seal to every outgoing message that asks for it.
 *
 * Hooked to MessageSending rather than done in the Blade masthead because a
 * notification's markdown is rendered before a message object exists — there is
 * no $message->embed() to call from inside the view. The template writes a
 * plain `cid:` reference and this listener supplies the part it points at, which
 * also means mailables, notifications and anything added later all get the same
 * treatment without remembering to.
 *
 * See App\Support\MailBranding for why the seal is embedded at all rather than
 * linked.
 */
class EmbedMailLogo
{
    public function handle(MessageSending $event): void
    {
        $path = MailBranding::logoPath();

        if ($path === null) {
            return;
        }

        $body = $event->message->getHtmlBody();

        // Only when the rendered message actually references the seal. A plain
        // text mail, or one built from a template that does not use the shared
        // masthead, should not quietly grow an attachment nobody displays —
        // attachments change how a message looks in the inbox (the paperclip)
        // and how spam filters weigh it.
        if (! is_string($body) || ! str_contains($body, 'cid:'.MailBranding::LOGO_CID)) {
            return;
        }

        try {
            $event->message->embedFromPath($path, MailBranding::LOGO_CID, 'image/png');
        } catch (\Throwable $e) {
            // A masthead is not worth losing a training notice over. The text
            // wordmark underneath the seal carries the identification on its
            // own, which is the whole reason it is real text.
            Log::warning('Could not embed the mail logo.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
