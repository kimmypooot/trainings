<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Writes an `email_logs` row for every outbound message.
 *
 * Hooked to the framework's MessageSent event rather than to each notification,
 * so nothing has to remember to log itself — a new notification added later is
 * captured for free.
 */
class RecordSentEmail
{
    public function handle(MessageSent $event): void
    {
        $recipients = $event->message->getTo();

        if (empty($recipients)) {
            return;
        }

        foreach ($recipients as $address) {
            try {
                EmailLog::create([
                    'user_id' => User::where('email', $address->getAddress())->value('id'),
                    'recipient_email' => $address->getAddress(),
                    'recipient_name' => $address->getName() ?: null,
                    'subject' => (string) $event->message->getSubject(),
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // A failure to write the audit row must never take down the
                // send itself — the participant getting the mail matters more
                // than the bookkeeping.
                Log::warning('Could not record sent email.', [
                    'recipient' => $address->getAddress(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
