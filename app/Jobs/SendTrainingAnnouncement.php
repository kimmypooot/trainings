<?php

namespace App\Jobs;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Notifications\StaffAnnouncement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends an HRD announcement to a training's participants.
 *
 * Queued and chunked because a large roster would otherwise hold the request
 * open while it works through a few hundred SMTP round trips.
 */
class SendTrainingAnnouncement implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $statuses  Registration statuses to include.
     */
    public function __construct(
        private readonly Training $training,
        private readonly string $subject,
        private readonly string $message,
        private readonly array $statuses,
        private readonly ?int $fieldOfficeId = null,
    ) {}

    public function handle(): void
    {
        Registration::with('user')
            ->where('training_id', $this->training->getKey())
            ->whereIn('status', $this->statuses)
            // Honours the same office scoping as the roster: a field office
            // sending an announcement must not reach another office's people.
            ->when($this->fieldOfficeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $this->fieldOfficeId)
            ))
            ->chunkById(100, function ($registrations) {
                foreach ($registrations as $registration) {
                    $registration->user?->notify(new StaffAnnouncement(
                        $this->subject,
                        $this->message,
                        route('trainings.show', $this->training->slug),
                    ));
                }
            });
    }

    /**
     * The statuses HRD can address, in the order they are offered.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function audiences(): array
    {
        return array_map(
            fn (RegistrationStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            [
                RegistrationStatus::Approved,
                RegistrationStatus::Pending,
                RegistrationStatus::Waitlisted,
                RegistrationStatus::Completed,
            ]
        );
    }
}
