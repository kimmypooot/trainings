<?php

namespace App\Console\Commands;

use App\Models\EvaluationInvitation;
use App\Notifications\EvaluationRequested;
use App\Support\SmeEvaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Ask today's participants to evaluate today's session.
 *
 * Scheduled for late afternoon, once the day's sessions have finished. The
 * evaluation is the one part of this system that depends entirely on somebody
 * choosing to answer, and a form nobody is told about collects nothing — the
 * badge in the sidebar only reaches participants who happen to sign in.
 */
class SendEvaluationInvitations extends Command
{
    protected $signature = 'tims:invite-evaluations
                            {--date= : The training day to invite for (defaults to today)}
                            {--dry-run : List who would be written to without sending}';

    protected $description = 'Invite participants to evaluate the training day that has just finished';

    public function handle(): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $due = SmeEvaluationService::invitationsDueOn($date);

        if ($due->isEmpty()) {
            $this->info("No evaluations to invite for {$date->toDateString()}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($due as $invitation) {
                $this->line(sprintf(
                    '  %s — day %d of %s',
                    $invitation['registration']->user->email,
                    $invitation['day'],
                    $invitation['registration']->training->title,
                ));
            }

            $this->info("{$due->count()} invitation(s) would be sent for {$date->toDateString()}.");

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($due as $invitation) {
            $registration = $invitation['registration'];

            /*
             * The record is written *before* the notification is dispatched,
             * and a clash on the unique index is what makes this safe to run
             * twice at once. Sending first and recording after would mail the
             * room again if the second write failed; this way the worst case is
             * a recorded invitation that was never mailed, which the
             * participant still sees as a badge and a list entry.
             */
            try {
                EvaluationInvitation::create([
                    'registration_id' => $registration->getKey(),
                    'day_number' => $invitation['day'],
                    'sent_at' => now(),
                ]);
            } catch (QueryException) {
                continue;
            }

            $registration->user?->notify(new EvaluationRequested(
                $registration,
                $invitation['day'],
                $invitation['experts'],
            ));

            $sent++;
        }

        $this->info("Queued {$sent} evaluation invitation(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
