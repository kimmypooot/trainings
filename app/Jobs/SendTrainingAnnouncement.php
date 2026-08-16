<?php

namespace App\Jobs;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Notifications\StaffAnnouncement;
use App\Support\AnnouncementAudience;
use App\Support\EmailTemplateRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends an HRD announcement to a filtered set of participants.
 *
 * Queued and chunked because a large roster would otherwise hold the request
 * open while it works through a few hundred SMTP round trips.
 *
 * The recipient set comes from AnnouncementAudience, the same builder the
 * compose screen counts and previews with, so what the sender was shown is what
 * actually goes out.
 */
class SendTrainingAnnouncement implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $filters  Training, statuses, sectors, regions.
     */
    public function __construct(
        private readonly array $filters,
        private readonly string $subject,
        private readonly string $body,
        private readonly ?int $fieldOfficeId = null,
    ) {}

    public function handle(): void
    {
        // One message per person, not per registration. Someone registered for
        // two of the selected trainings gets a single email — receiving the
        // same announcement twice reads as a system fault.
        $reached = [];

        AnnouncementAudience::query($this->filters, $this->fieldOfficeId)
            ->chunkById(100, function ($registrations) use (&$reached) {
                foreach ($registrations as $registration) {
                    $user = $registration->user;

                    if ($user === null || isset($reached[$user->getKey()])) {
                        continue;
                    }

                    $reached[$user->getKey()] = true;

                    $user->notify(new StaffAnnouncement(
                        EmailTemplateRenderer::render($this->subject, $registration),
                        EmailTemplateRenderer::render($this->body, $registration),
                        $registration->training
                            ? route('trainings.show', $registration->training->slug)
                            : null,
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
