<?php

namespace App\Console\Commands;

use App\Enums\RegistrationStatus;
use App\Enums\TrainingStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Notifications\TrainingReminder;
use Illuminate\Console\Command;

class SendTrainingReminders extends Command
{
    protected $signature = 'tims:send-reminders {--days=1 : How many days ahead to look}';

    protected $description = 'Remind confirmed participants about trainings starting soon';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $target = now()->addDays($days);

        $trainings = Training::where('status', TrainingStatus::Published)
            ->whereBetween('starts_at', [$target->copy()->startOfDay(), $target->copy()->endOfDay()])
            ->get();

        $sent = 0;

        foreach ($trainings as $training) {
            Registration::with('user')
                ->where('training_id', $training->getKey())
                // Only people actually holding a confirmed slot — reminding a
                // rejected applicant to bring their QR code would be cruel.
                ->where('status', RegistrationStatus::Approved)
                ->chunkById(100, function ($registrations) use ($training, &$sent) {
                    foreach ($registrations as $registration) {
                        $registration->user->notify(new TrainingReminder($training));
                        $sent++;
                    }
                });
        }

        $this->info("Queued {$sent} reminder(s) across {$trainings->count()} training(s).");

        return self::SUCCESS;
    }
}
